<?php

namespace Goldnead\StatamicProducts\Http\Resources\Cp;

use Illuminate\Http\Resources\Json\ResourceCollection;
use Statamic\CP\Column;
use Statamic\CP\Columns;
use Statamic\Http\Resources\CP\Concerns\HasRequestedColumns;

/**
 * The listing payload, built the way core builds its own.
 *
 * `HasRequestedColumns` plus `setPreferred()` is what makes the column picker
 * real rather than a control that reports success and changes nothing.
 */
class ProductsCollection extends ResourceCollection
{
    use HasRequestedColumns;

    public $collects = ListedProduct::class;

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
            Column::make('name')->label(__('statamic-products::messages.column_name'))->sortable(true)->defaultOrder(1),
            Column::make('handle')->label(__('statamic-products::messages.column_handle'))->sortable(true)->defaultOrder(2),
            Column::make('type')->label(__('statamic-products::messages.column_type'))->sortable(true)->defaultOrder(3),
            Column::make('amount')->label(__('statamic-products::messages.column_amount'))->sortable(true)->numeric(true)->defaultOrder(4),
            Column::make('digital')->label(__('statamic-products::messages.column_digital'))->sortable(true)->defaultOrder(5),
            Column::make('grants')->label(__('statamic-products::messages.column_grants'))->sortable(false)->numeric(true)->defaultOrder(6),
            Column::make('active')->label(__('statamic-products::messages.column_active'))->sortable(true)->defaultOrder(7),
            // Off by default: most catalogues are downloads and access, where
            // the pointer is an id nobody reads. It earns a column only on a
            // site that files dates and cohorts. Switching it off hides no
            // defect: what guarantees a dangling pointer is seen is the count
            // above the table, which no column preference can reach.
            Column::make('ref')->label(__('statamic-products::messages.column_ref'))->sortable(false)->defaultOrder(8)->defaultVisibility(false)->visible(false),
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
                // Read out of every response by the Listing; missing, the read
                // throws inside its own promise and the screen shows "Something
                // went wrong" over a page where everything works.
                'columns' => $this->visibleColumns(),
            ],
        ];
    }
}
