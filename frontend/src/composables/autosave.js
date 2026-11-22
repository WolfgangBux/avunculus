// Automatisch speichern: kurz nach der letzten Änderung. Speichervorgänge laufen nacheinander,
// ein Vorgang erfasst den Stand erst, wenn der vorige fertig ist (neue Zeilen haben dann ihre IDs).
import { ref, watch, toRaw, onBeforeUnmount } from 'vue'
import { useNotify } from './feedback.js'
import { t } from '../i18n/index.js'

/**
 * const autosave = useAutosave(job)
 *   job(saving): Promise<'saved' | 'invalid'> – speichert den aktuellen Stand; ruft saving() auf,
 *                wenn es tatsächlich etwas zu senden gibt; wirft bei einem Fehler
 *   autosave.changed()  – nach jeder Änderung
 *   autosave.flush(job) – Ausstehendes sofort speichern (optional mit einem eigenen job, z. B. für
 *                         den Stand einer Person, die gerade verlassen wird); liefert den Status
 *   autosave.status     – 'saved' | 'pending' | 'saving' | 'invalid' | 'error'
 */
export function useAutosave(job, { delay = 800 } = {}) {
  const status = ref('saved')
  let timer   = null
  let queue   = Promise.resolve()
  let waiting = 0

  function enqueue(run) {
    clearTimeout(timer)
    timer = null
    waiting++
    queue = queue
      .then(() => run(() => { status.value = 'saving' }))
      .catch(() => 'error')
      .then(result => {
        waiting--
        if (!waiting) status.value = timer ? 'pending' : result
        return result
      })
    return queue
  }

  function changed() {
    clearTimeout(timer)
    timer = setTimeout(() => enqueue(job), delay)
    if (!waiting) status.value = 'pending'
  }

  function flush(run = job) {
    if (timer || ['invalid', 'error'].includes(status.value)) return enqueue(run)
    return queue.then(() => status.value)
  }

  /** Nichts Ausstehendes mehr (z. B. nach dem Laden eines anderen Datensatzes) */
  function cancel() {
    clearTimeout(timer)
    timer = null
    if (!waiting) status.value = 'saved'
  }

  return { status, changed, flush, cancel }
}

/**
 * Formular für einen Datensatz (Person, Quelle, Ort …) automatisch speichern.
 *
 *   form            Ref auf das Formularobjekt; load() setzt für jeden Datensatz ein neues Objekt,
 *                   damit ein Speichervorgang für einen gerade verlassenen Datensatz mit dessen
 *                   Stand zu Ende laufen kann
 *   record()        der angezeigte Datensatz (null = neuer)
 *   load(record)    Formular füllen (form.value = …)
 *   payload(form)   zu sendende Daten – ohne das Formular zu ändern (z. B. Datum schon in GEDCOM-Form)
 *   validate(form, { current, isNew })  true, wenn speicherbar; Fehler nur anzeigen, wenn current
 *   save(id, data)  Promise<gespeicherter Datensatz>; id null = anlegen
 *   saved(rec, shown)  nach jedem Speichern; shown = das Formular zeigt diesen Datensatz noch
 *   before(form)    optional: vor dem Senden festhalten, was adopt() braucht (z. B. die gesendeten Zeilen)
 *   adopt(ctx, data, rec)  optional: IDs neuer Zeilen ins Formular und in data übernehmen;
 *                   false = Zuordnung unsicher, das Formular lädt den Datensatz neu
 *   onError(e, current)  optional: Fehler selbst anzeigen (z. B. Feldfehler); true = erledigt
 *   enabled()       optional: darf gespeichert werden (Leserechte: nein)
 *
 * Liefert { status, flush, discard }.
 *
 * Kommt derselbe Datensatz neu geladen zurück (eigene Speicherung, Nachladen), bleibt das Formular,
 * solange noch etwas aussteht; sonst übernimmt es den Stand vom Server.
 */
export function useRecordAutosave(o) {
  const enabled = o.enabled ?? (() => true)
  const notify  = useNotify()
  let current   = { form: o.form.value, id: null, savedJson: '' }
  let lastSaved = null
  let mustReload = false   // IDs neuer Zeilen ließen sich nicht zuordnen

  async function saveState(state, saving) {
    if (state.discarded) return 'saved'
    if (!o.validate(state.form, { current: state === current, isNew: !state.id })) return 'invalid'
    const data = o.payload(state.form)
    const json = JSON.stringify(data)
    if (json === state.savedJson) return 'saved'

    saving()
    const ctx = o.before?.(state.form)
    let rec
    try {
      rec = await o.save(state.id, data)
    } catch (e) {
      if (!o.onError?.(e, state === current)) notify.error(t('action.saveError'))
      throw e
    }
    state.id = rec.id
    const complete = o.adopt ? o.adopt(ctx, data, rec) !== false : true
    state.savedJson = JSON.stringify(data)
    const shown = state === current
    if (shown && complete) lastSaved = rec
    if (shown && !complete) mustReload = true
    o.saved(rec, shown)
    return 'saved'
  }

  const autosave = useAutosave(saving => saveState(current, saving))
  const { status } = autosave

  // Nur echte Änderungen zählen (nicht das Laden, nicht das Übernehmen neuer IDs)
  watch(() => JSON.stringify(o.payload(o.form.value)), json => {
    if (!enabled()) return
    if (json === current.savedJson) autosave.cancel()
    else autosave.changed()
  })

  /** Ausstehendes sofort speichern – auch für einen Datensatz, der gerade verlassen wird */
  function flush() {
    const state = current
    return autosave.flush(saving => saveState(state, saving))
  }

  watch(o.record, rec => {
    if (!mustReload) {
      if (rec && toRaw(rec) === lastSaved) return   // eben selbst gespeichert
      if (rec && current.id && rec.id === current.id && status.value !== 'saved') return   // Eingaben nicht überschreiben
    }
    mustReload = false
    lastSaved  = null
    flush()
    o.load(rec)
    current = { form: o.form.value, id: rec?.id ?? null, savedJson: JSON.stringify(o.payload(o.form.value)) }
    autosave.cancel()
  }, { immediate: true })

  // Seite schließen, während noch etwas ungespeichert ist: Browser fragt nach
  function warnUnsaved(e) {
    if (status.value === 'saved') return
    flush()
    e.preventDefault()
  }
  window.addEventListener('beforeunload', warnUnsaved)
  onBeforeUnmount(() => {
    flush()
    window.removeEventListener('beforeunload', warnUnsaved)
  })

  /** Ausstehendes verwerfen, z. B. weil der Datensatz gleich gelöscht wird */
  function discard() {
    current.discarded = true
    autosave.cancel()
  }

  return { status, flush, discard }
}
