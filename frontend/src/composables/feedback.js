import { useConfirm } from 'openvue/useconfirm'
import { useToast } from 'openvue/usetoast'
import { t } from '../i18n/index.js'

/**
 * Promise-basierter Ersatz für window.confirm():
 *   const ask = useAsk()
 *   if (!(await ask(message))) return
 */
export function useAsk() {
  const confirm = useConfirm()
  return (message, { acceptLabel = t('action.delete'), danger = true } = {}) =>
    new Promise(resolve => {
      let done = false
      const finish = v => { if (!done) { done = true; resolve(v) } }
      confirm.require({
        message,
        header:      t('confirm.header'),
        icon:        'pi pi-exclamation-triangle',
        rejectProps: { label: t('action.cancel'), severity: 'secondary', outlined: true },
        acceptProps: { label: acceptLabel, severity: danger ? 'danger' : undefined },
        accept: () => finish(true),
        reject: () => finish(false),
        onHide: () => finish(false),
      })
    })
}

/** Kurze Rückmeldungen als Toast. */
export function useNotify() {
  const toast = useToast()
  return {
    success: summary => toast.add({ severity: 'success', summary, life: 4000 }),
    error:   summary => toast.add({ severity: 'error',   summary, life: 6000 }),
    info:    summary => toast.add({ severity: 'info',    summary, life: 4000 }),
  }
}
