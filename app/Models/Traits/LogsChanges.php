<?php

namespace App\Models\Traits;

use App\Enums\ActionType;
use App\Models\Actionlog;

trait LogsChanges {

    private ?ActionType $action_type = null;
    private array $meta = [];
    static function bootLogsChanges() {
        static::creating(function ($model) {
            \Log::error("CREATING!");
            $model->action_type = ActionType::Create;
        });

        static::updating(function ($model) {
            \Log::error("UPDATING!");
            if(!$model->action_type) {
                \Log::error("No action type - so definitely doing 'update'!");
                $model->action_type = ActionType::Update;
            }
        });

        // The Settings object does not use soft-deletes, so it has no 'restoring' method
        // so to be generic, we just look for that method existing at all, and don't call
        // it if it doesn't.
        if (method_exists(self::class, 'restoring')) {
            static::restoring(function ($model) {
                $model->action_type = ActionType::Restore;
            });
        }

        /* The main functionality is here: */
        static::saving(function ($model) {
            \Log::error("recording changes.......");
            $model->record_changes();
        });

        static::saved(function ($model) {
            \Log::error("SAVED!!!!");
            $model->add_action_log();
        });

        static::deleted(function ($model) {
            $model->action_type = ActionType::Delete;
            $model->add_action_log();
            \Log::error("deleted!!!!!!!!!!!");
        });
    }

    function record_changes()
    {
        $changed = [];

        // something here with custom fields is needed? or will getRawOriginal et al just do that for us?
        foreach ($this->getRawOriginal() as $key => $value) { //on 'create' this doesn't write down the new attributes
            if ($this->getRawOriginal()[$key] != $this->getAttributes()[$key]) {
                $changed[$key]['old'] = $this->getRawOriginal()[$key];
                $changed[$key]['new'] = $this->getAttributes()[$key];

                if (property_exists($this, 'hidden') && in_array($key, $this->hidden)) {
                    $changed[$key]['old'] = '*************'; //FIXME deleted_at is hidden?!
                    $changed[$key]['new'] = '*************';
                }
            }
        }
        $this->meta = $changed;
    }

    function add_action_log()
    {
        if(!$this->action_type && !$this->meta) {
            \Log::warning("No action type set, and no changes to record. Not logging.");
            return;
        }
        if($this->action_type == ActionType::Update && !$this->meta) {
            \Log::warning("An update with no actual changes to record. Not logging");
            return;
        }
        $logAction = new Actionlog();
        $logAction->action_type = $this->action_type->value;
        $logAction->item()->associate($this);
        $logAction->created_at = date('Y-m-d H:i:s');
        $logAction->action_date = date('Y-m-d H:i:s');
        // target_id and target_type?
        // need IP and user-agent!!!!!
        $logAction->created_by = auth()->id();
        $logAction->log_meta = $this->meta ? json_encode($this->meta) : null; // this gets weird on 'create'
        if($logAction->save()) {
            //success! Reset for more actions later...
            $this->action_type = null;
            $this->meta = [];
        }
    }
}