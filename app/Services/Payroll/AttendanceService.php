<?php

namespace App\Services\Payroll;

use App\Models\AttendanceRecord;
use App\Models\EmployeeProfile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Attendance capture ("Jeweller Plan" model): the app checks an employee in with a
 * front-camera selfie + GPS fix, and out at day end. HQ can mark manual entries.
 * One record per employee per day (IST); payroll reads these to prorate salary.
 */
class AttendanceService
{
    public const SELFIE_DISK = 'public';

    /** Check in for today. Throws if already checked in or outside the geofence. */
    public function checkIn(EmployeeProfile $employee, array $geo = [], ?UploadedFile $selfie = null, string $source = 'app'): AttendanceRecord
    {
        $this->assertActive($employee);
        if ($source === 'app') {
            // The L-BOX ('device') is bolted to the branch — geofence only guards the app.
            $this->assertWithinGeofence($employee, $geo);
        }
        $today = Carbon::today()->toDateString();

        $existing = AttendanceRecord::where('employee_profile_id', $employee->id)
            ->whereDate('date', $today)->first();

        if ($existing && $existing->check_in_at) {
            throw new \RuntimeException('Already checked in today.');
        }

        $record = $existing ?? new AttendanceRecord([
            'employee_profile_id' => $employee->id,
            'date' => $today,
        ]);

        $record->fill([
            'check_in_at' => Carbon::now(),
            'selfie_path' => $selfie ? $this->storeSelfie($employee, $selfie, 'in') : $record->selfie_path,
            'lat' => $geo['lat'] ?? null,
            'lng' => $geo['lng'] ?? null,
            'accuracy_m' => $geo['accuracy'] ?? null,
            'source' => $source,
            'status' => 'present',
        ])->save();

        return $record;
    }

    /** Check out for today. Requires an open check-in. */
    public function checkOut(EmployeeProfile $employee, array $geo = [], ?UploadedFile $selfie = null): AttendanceRecord
    {
        $this->assertActive($employee);

        $record = AttendanceRecord::where('employee_profile_id', $employee->id)
            ->whereDate('date', Carbon::today()->toDateString())->first();

        if (! $record || ! $record->check_in_at) {
            throw new \RuntimeException('No check-in found for today.');
        }
        if ($record->check_out_at) {
            throw new \RuntimeException('Already checked out today.');
        }

        $record->fill([
            'check_out_at' => Carbon::now(),
            'checkout_selfie_path' => $selfie ? $this->storeSelfie($employee, $selfie, 'out') : null,
            'checkout_lat' => $geo['lat'] ?? null,
            'checkout_lng' => $geo['lng'] ?? null,
        ])->save();

        return $record;
    }

    /** HQ manual entry — upserts the day's record with an explicit status. */
    public function markManual(EmployeeProfile $employee, string $date, string $status, ?string $note = null, ?int $userId = null): AttendanceRecord
    {
        if (! in_array($status, ['present', 'half_day', 'absent', 'leave', 'paid_leave'], true)) {
            throw new \InvalidArgumentException("Invalid attendance status [{$status}].");
        }

        return AttendanceRecord::updateOrCreate(
            ['employee_profile_id' => $employee->id, 'date' => Carbon::parse($date)->toDateString()],
            ['status' => $status, 'source' => 'manual', 'note' => $note, 'marked_by' => $userId],
        );
    }

    /** Longest side a stored selfie keeps; anything bigger is downscaled (board 2026-08-23: save storage). */
    public const SELFIE_MAX_PX = 1024;

    public const SELFIE_JPEG_QUALITY = 72;

    protected function storeSelfie(EmployeeProfile $employee, UploadedFile $file, string $suffix): string
    {
        $dir = "attendance/{$employee->id}";
        $day = Carbon::today()->toDateString();

        // Re-encode through GD: a phone selfie arrives at 2–6 MB; a 1024px JPEG
        // at q72 is ~100 KB and is all the admin preview ever needs. Anything
        // GD cannot read (or a GD failure) is stored exactly as uploaded.
        if ($compressed = $this->compressSelfie($file)) {
            $path = "{$dir}/{$day}-{$suffix}.jpg";
            Storage::disk(self::SELFIE_DISK)->put($path, $compressed);

            return $path;
        }

        return $file->storeAs($dir, "{$day}-{$suffix}." . ($file->extension() ?: 'jpg'), self::SELFIE_DISK);
    }

    /** JPEG bytes of the downscaled image, or null when GD can't handle it. */
    protected function compressSelfie(UploadedFile $file): ?string
    {
        if (! function_exists('imagecreatefromstring')) {
            return null;
        }
        try {
            $src = @imagecreatefromstring((string) file_get_contents($file->getRealPath()));
            if (! $src) {
                return null;
            }
            // Honour the phone's EXIF orientation so the stored JPEG is upright.
            if (function_exists('exif_read_data') && in_array(strtolower((string) $file->extension()), ['jpg', 'jpeg'], true)) {
                $exif = @exif_read_data($file->getRealPath());
                $src = match ((int) ($exif['Orientation'] ?? 1)) {
                    3 => imagerotate($src, 180, 0),
                    6 => imagerotate($src, -90, 0),
                    8 => imagerotate($src, 90, 0),
                    default => $src,
                };
            }
            $w = imagesx($src);
            $h = imagesy($src);
            $scale = min(1, self::SELFIE_MAX_PX / max($w, $h));
            if ($scale < 1) {
                $dst = imagescale($src, (int) round($w * $scale), (int) round($h * $scale), IMG_BICUBIC);
                if ($dst) {
                    imagedestroy($src);
                    $src = $dst;
                }
            }
            ob_start();
            imagejpeg($src, null, self::SELFIE_JPEG_QUALITY);
            imagedestroy($src);

            return (string) ob_get_clean() ?: null;
        } catch (\Throwable) {
            return null;
        }
    }

    protected function assertActive(EmployeeProfile $employee): void
    {
        if ($employee->status !== 'active') {
            throw new \RuntimeException('Employee profile is not active.');
        }
    }

    /**
     * When the profile sets a geofence radius AND the member's branch has a map
     * pin, the check-in fix must fall inside it. No radius / no pin → no check.
     */
    protected function assertWithinGeofence(EmployeeProfile $employee, array $geo): void
    {
        $radius = $employee->geofence_radius_m;
        if (! $radius) {
            return;
        }

        $branch = $employee->member?->branch;
        if (! $branch || $branch->latitude === null || $branch->longitude === null) {
            return;
        }

        if (! isset($geo['lat'], $geo['lng'])) {
            throw new \RuntimeException('Location is required to check in at this branch.');
        }

        $distance = $this->haversineMeters(
            (float) $geo['lat'], (float) $geo['lng'],
            (float) $branch->latitude, (float) $branch->longitude,
        );

        if ($distance > $radius) {
            throw new \RuntimeException(sprintf(
                'You are %s from %s — move within %dm of the branch to check in.',
                $distance >= 1000 ? round($distance / 1000, 1) . 'km' : round($distance) . 'm',
                $branch->name, $radius,
            ));
        }
    }

    protected function haversineMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
