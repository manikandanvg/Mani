<?php

namespace App\Http\Resources;

use App\Support\Translatable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * A storefront category (optionally with its child subcategories when loaded).
 */
class CategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $lang = $request->query('lang');

        return [
            'id' => $this->id,
            'name' => Translatable::pick($this->name, $lang),
            'slug' => $this->slug,
            'material' => $this->material,
            'image' => static::imageUrl($this->image_path),
            'children' => CategoryResource::collection($this->whenLoaded('children')),
        ];
    }

    protected static function imageUrl(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        return Str::startsWith($path, ['http://', 'https://'])
            ? $path
            : Storage::disk('public')->url($path);
    }
}
