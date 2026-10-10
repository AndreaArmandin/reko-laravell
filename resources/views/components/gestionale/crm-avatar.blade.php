@props(['name', 'id'])
<span aria-hidden="true" class="crm-avatar crm-avatar--{{ \App\Gestionale\Crm\Presenter::avatarTone($id) }}">{{ \App\Gestionale\Crm\Presenter::initials($name) }}</span>
