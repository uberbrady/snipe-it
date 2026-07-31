@aware(['name'])

@if (request('status') !== 'deleted')
    @canany(['update', 'delete'], \App\Models\AssetModel::class)
        <x-table.bulk-actions
            :name="$name"
            :action_route="route('models.bulkedit.index')"
            model_name="assetmodel"
            :actions="[
                'edit' => ['label' => trans('general.bulk_edit')],
                'delete' => ['label' => trans('general.bulk_delete')],
            ]"
        />
    @endcanany
@endif
