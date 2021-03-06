<?php

namespace Railken\EloquentMapper\Tests\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations;

class Tag extends Model
{
    public function taggable(): Relations\MorphTo
    {
        return $this->morphTo();
    }
}
