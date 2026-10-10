@props(['model', 'value' => [], 'label' => 'Tag liberi', 'live' => true])
@php
    $uid = 'tag-input-'.\Illuminate\Support\Str::random(6);
    $aliases = ['terrazzo' => 'terrazza', 'terrazzi' => 'terrazza', 'terrazze' => 'terrazza', 'arredata' => 'arredato', 'arredati' => 'arredato',
        'arredate' => 'arredato', 'ristrutturata' => 'ristrutturato', 'ristrutturati' => 'ristrutturato', 'ristrutturate' => 'ristrutturato',
        'box' => 'garage', 'box auto' => 'garage', 'accessibile' => 'senza barriere', 'senza barriere architettoniche' => 'senza barriere'];
@endphp

{{--
    tag-input.tsx TagInput: tag liberi (massimo 30 da 60 caratteri), normalizzati e senza doppioni di significato
    (tags.ts normalizeTags / tagKey), con i suggerimenti. L'elenco va alla proprietà Livewire $model: subito
    (live, salvataggio automatico delle risposte) oppure alla conferma della finestra.
--}}
<section class="crm-tag-input" aria-label="{{ $label }}" wire:ignore
    x-data="{
        tags: @js(array_values((array) $value)), draft: '', aliases: @js($aliases), suggestions: @js(\App\Gestionale\Questionnaire\Tags::SUGGESTIONS),
        get full() { return this.tags.length >= 30 },
        key(tag) {
            const folded = tag.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLocaleLowerCase('it-IT').replace(/[^a-z0-9]+/g, ' ').trim()
            return this.aliases[folded] ?? folded
        },
        normalize(values) {
            const seen = new Set()
            return values.map(v => v.replace(/^#+/, '').trim().replace(/\s+/g, ' ')).filter(v => { const k = this.key(v); if (!k || seen.has(k)) return false; seen.add(k); return true })
        },
        commit(list) { this.tags = list; $wire.set(@js($model), list, @js((bool) $live)) },
        add(text = this.draft) { if (!text.trim() || this.full) return; this.commit(this.normalize([...this.tags, text.trim().slice(0, 60)])); this.draft = '' },
        remove(tag) { this.commit(this.tags.filter(item => this.key(item) !== this.key(tag))) },
        free() { return this.suggestions.filter(tag => !this.tags.some(existing => this.key(existing) === this.key(tag))) },
    }">
    <label class="crm-field" for="{{ $uid }}"><span>{{ $label }}</span></label>
    <div class="crm-tags">
        <template x-for="tag in tags" :key="key(tag)">
            <span><span x-text="tag"></span><button type="button" :aria-label="'Rimuovi tag ' + tag" x-on:click="remove(tag)"><x-gestionale.lucide name="x" :size="13" /></button></span>
        </template>
    </div>
    <div class="crm-tag-entry">
        <input id="{{ $uid }}" x-model="draft" maxlength="60" :disabled="full" placeholder="Scrivi un tag e premi Invio" x-on:keydown.enter.prevent="add()">
        <button type="button" class="crm-btn secondary" :disabled="! draft.trim() || full" x-on:click="add()" aria-label="Aggiungi tag"><x-gestionale.lucide name="plus" :size="16" /></button>
    </div>
    <div class="crm-tag-suggestions">
        <template x-for="tag in free()" :key="tag">
            <button type="button" :disabled="full" x-on:click="add(tag)" x-text="'+ ' + tag"></button>
        </template>
    </div>
    <small>Scegli un esempio o crea il tuo tag. <span x-text="tags.length"></span>/30</small>
</section>
