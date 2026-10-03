<?php

namespace Goldnead\StatamicProducts\Http\Resources\Cp;

use Illuminate\Http\Resources\Json\ResourceCollection;
use Statamic\CP\Column;
use Statamic\CP\Columns;
use Statamic\Http\Resources\CP\Concerns\HasRequestedColumns;

/**
 * Die Zugangsliste, gebaut wie die Produktliste daneben.
 */
class AccessesCollection extends ResourceCollection
{
    use HasRequestedColumns;

    public $collects = ListedAccess::class;

    protected $columns;

    protected ?string $columnPreferenceKey = null;

    public function columnPreferenceKey(string $key): self
    {
        $this->columnPreferenceKey = $key;

        return $this;
    }

    private function setColumns(): self
    {
        $columns = new Columns([
            Column::make('name')->label(__('statamic-products::messages.column_access'))->sortable(true)->defaultOrder(1),
            Column::make('handle')->label(__('statamic-products::messages.column_handle'))->sortable(true)->defaultOrder(2),
            Column::make('contents')->label(__('statamic-products::messages.col_contents'))->sortable(false)->numeric(true)->defaultOrder(3),
            Column::make('credits')->label(__('statamic-products::messages.col_credits'))->sortable(false)->defaultOrder(4),
            Column::make('members')->label(__('statamic-products::messages.col_members'))->sortable(true)->defaultOrder(5),
            Column::make('active')->label(__('statamic-products::messages.column_active'))->sortable(true)->defaultOrder(6),
        ]);

        if ($key = $this->columnPreferenceKey) {
            $columns->setPreferred($key);
        }

        $this->columns = $columns->rejectUnlisted()->values();

        return $this;
    }

    public function toArray($request)
    {
        $this->setColumns();

        return $this->collection;
    }

    public function with($request)
    {
        return [
            'meta' => [
                'columns' => $this->visibleColumns(),
            ],
        ];
    }
}
