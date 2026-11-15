<?php

namespace App\Filament\Support;

use App\Models\Branch;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * The table every screen gets (bound over Filament's Table in AppServiceProvider) — the
 * board's "improve the data-table" list of 2026-11-15, done once instead of in 80 resources:
 *
 *   1. rows never open on click — only the action buttons act;
 *   2. every plain text column is searchable (own columns and one-hop relations);
 *   3. every amount column carries a footer total (this page + all rows);
 *   4. generic filters where the table has none: branch, status, date range;
 *   5. every branch filter is searchable by "name (in-charge / city / phone)".
 *
 * All of it is applied lazily, the first time the finished table is read, so it sees the
 * columns and filters the resource declared and never overrides a choice made there.
 */
class IclTable extends Table
{
    protected bool $iclEnhanced = false;

    /** Amount columns: marked by the ->baseMoney() macro (AppServiceProvider). */
    protected static ?\WeakMap $moneyColumns = null;

    /** Amount-looking column names that get a total even without ->baseMoney(). */
    protected const MONEY_NAME = '/(^|_)(amount|total|worth|tds|charge|com_value|cash_worth|grand_total|net_amount)$/';

    public static function markMoney(object $column): void
    {
        static::$moneyColumns ??= new \WeakMap;
        static::$moneyColumns[$column] = true;
    }

    public static function isMoney(object $column): bool
    {
        return static::$moneyColumns !== null && isset(static::$moneyColumns[$column]);
    }

    // ── 1. no row click ──────────────────────────────────────────────────────────────
    public function getRecordUrl(Model $record): ?string
    {
        return null;
    }

    public function getRecordAction(Model $record): ?string
    {
        return null;
    }

    // ── lazy hook ────────────────────────────────────────────────────────────────────
    public function getColumns(): array
    {
        $this->iclEnhance();

        return parent::getColumns();
    }

    public function getFilters(bool $withHidden = false): array
    {
        $this->iclEnhance();

        return parent::getFilters($withHidden);
    }

    protected function iclEnhance(): void
    {
        if ($this->iclEnhanced || $this->columns === []) {
            return;
        }
        $this->iclEnhanced = true;

        try {
            $model = $this->getQuery()->getModel();
        } catch (\Throwable) {
            $model = null;
        }
        if (! $model instanceof Model) {
            return;
        }

        try {
            $types = static::columnTypes($model);
            if ($types === []) {
                return;
            }
            $this->iclColumns($model, $types);
            $this->iclFilters($model, $types);
        } catch (\Throwable $e) {
            report($e);   // a convenience layer must never take a screen down
        }
    }

    /** [column => type] of a model's table, cached (information_schema is slow to ask each request). */
    public static function columnTypes(Model $model): array
    {
        $table = $model->getTable();
        $conn = $model->getConnectionName() ?? config('database.default');

        return Cache::remember("icl-table-cols:{$conn}:{$table}", 600, function () use ($model, $table) {
            try {
                return collect(Schema::connection($model->getConnectionName())->getColumns($table))
                    ->mapWithKeys(fn ($c) => [$c['name'] => strtolower((string) $c['type'])])->all();
            } catch (\Throwable) {
                return [];
            }
        });
    }

    protected static function isText(?string $type): bool
    {
        return $type !== null && (bool) preg_match('/^(varchar|char|text|tinytext|mediumtext|longtext|enum)/', $type);
    }

    protected static function isNumber(?string $type): bool
    {
        return $type !== null && (bool) preg_match('/^(decimal|double|float|int|bigint|smallint|mediumint|numeric)/', $type);
    }

    protected static function isDate(?string $type): bool
    {
        return $type !== null && (bool) preg_match('/^(date|datetime|timestamp)/', $type);
    }

    protected static function hasCustomState(object $column): bool
    {
        static $prop = null;
        $prop ??= new \ReflectionProperty(TextColumn::class, 'getStateUsing');

        return $prop->getValue($column) !== null;
    }

    // ── 2 + 3. search and totals ─────────────────────────────────────────────────────
    protected function iclColumns(Model $model, array $types): void
    {
        foreach ($this->columns as $column) {
            if (! $column instanceof TextColumn || static::hasCustomState($column)) {
                continue;
            }
            $name = $column->getName();

            if (! str_contains($name, '.')) {
                $type = $types[$name] ?? null;
                if (! $column->isSearchable() && static::isText($type)) {
                    $column->searchable();
                }
                // a rate / price per gram is money too, but a total of rates means nothing
                if (static::isNumber($type) && ! $column->hasSummary() && ! preg_match('/(^|_)(rate|price)$/', $name)
                    && (static::isMoney($column) || (str_starts_with((string) $type, 'decimal') && preg_match(self::MONEY_NAME, $name)))) {
                    $column->summarize(Sum::make()->label(__('Total'))->baseMoney());
                }

                continue;
            }

            // one hop: "member.name", "branch.phone" …
            if ($column->isSearchable() || substr_count($name, '.') !== 1) {
                continue;
            }
            [$relation, $attribute] = explode('.', $name);
            if (! $model->isRelation($relation)) {
                continue;
            }
            $rel = $model->{$relation}();
            if (! $rel instanceof BelongsTo && ! $rel instanceof HasOne) {
                continue;
            }
            if (static::isText(static::columnTypes($rel->getRelated())[$attribute] ?? null)) {
                $column->searchable();
            }
        }
    }

    // ── 4 + 5. filters ───────────────────────────────────────────────────────────────
    protected function iclFilters(Model $model, array $types): void
    {
        $names = array_keys($this->filters);
        $has = fn (string $pattern) => (bool) preg_grep($pattern, $names);
        $user = auth()->user();
        $dealer = (bool) ($user && method_exists($user, 'isDistributor') && $user->isDistributor());

        foreach ($this->filters as $filter) {
            if ($filter instanceof SelectFilter && preg_match('/branch(_id)?$/', $filter->getName())) {
                CommonFilters::fullBranchLabels($filter);
            }
        }

        $add = [];
        if (isset($types['branch_id']) && ! $dealer && ! $has('/branch/i') && $model->getTable() !== 'branches') {
            $add[] = CommonFilters::branch('branch_id', false);
        }
        if (isset($types['status']) && ! $has('/status|stage|state/i') && ($options = static::statusOptions($model, $types['status']))) {
            $add[] = SelectFilter::make('status')->label('Status')->options($options);
        }
        if (! $has('/date|range|between|period|month|_on$|_at$|from|until|interval|day/i') && ! $this->iclHasDateFilter()
            && ($col = $this->iclDateColumn($types))) {
            $add[] = CommonFilters::dateRange($col, (string) str($col)->replaceLast('_at', '')->replaceLast('_on', '')->headline());
        }

        foreach ($add as $filter) {
            $filter->table($this);
            $this->filters[$filter->getName()] = $filter;
        }
    }

    /** Does the resource already filter on a date (any filter whose form holds a date picker)? */
    protected function iclHasDateFilter(): bool
    {
        foreach ($this->filters as $filter) {
            try {
                foreach ($filter->getFormSchema() as $field) {
                    if ($field instanceof \Filament\Forms\Components\DateTimePicker) {   // DatePicker extends it
                        return true;
                    }
                }
            } catch (\Throwable) {
                // a filter that cannot describe its form simply does not count
            }
        }

        return false;
    }

    /** The date the table is about: the first date column it shows, else created_at. */
    protected function iclDateColumn(array $types): ?string
    {
        foreach ($this->columns as $column) {
            $name = $column->getName();
            if (! in_array($name, ['created_at', 'updated_at', 'deleted_at'], true) && static::isDate($types[$name] ?? null)) {
                return $name;
            }
        }

        // created_at only when the table lists it (shown or in the column chooser) — a small
        // master list (currencies, FAQs) gets no date filter it has no use for
        return isset($types['created_at'], $this->columns['created_at']) ? 'created_at' : null;
    }

    /** Status choices: the enum's own values, else the (few) values in use. */
    protected static function statusOptions(Model $model, string $type): array
    {
        if (preg_match('/^enum\((.*)\)$/', $type, $m)) {
            $values = array_map(fn ($v) => trim($v, " '\""), explode(',', $m[1]));
        } elseif (static::isText($type)) {
            $values = Cache::remember('icl-table-status:' . $model->getTable(), 600, function () use ($model) {
                try {
                    $v = $model->newQuery()->toBase()->whereNotNull('status')->distinct()->limit(16)->pluck('status')->all();

                    return count($v) > 15 ? [] : $v;
                } catch (\Throwable) {
                    return [];
                }
            });
        } else {
            return [];
        }

        return collect($values)->filter(fn ($v) => $v !== '' && $v !== null)
            ->mapWithKeys(fn ($v) => [$v => (string) str((string) $v)->replace('_', ' ')->ucfirst()])->all();
    }
}
