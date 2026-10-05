<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Private file the agency attaches to a request, optionally tied to one unit.
 */
class AgencyRequestAttachment extends Model
{
    protected $table = 'agency_request_attachments';

    protected $guarded = ['id'];

    /**
     * @return BelongsTo<AgencyRequest, $this>
     */
    public function agencyRequest(): BelongsTo
    {
        return $this->belongsTo(AgencyRequest::class);
    }

    /**
     * @return BelongsTo<CadastralUnit, $this>
     */
    public function cadastralUnit(): BelongsTo
    {
        return $this->belongsTo(CadastralUnit::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }
}
