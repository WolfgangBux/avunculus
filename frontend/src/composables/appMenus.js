import { computed } from 'vue'
import { t } from '../i18n/index.js'
import { isAdmin, canEdit, appVersion } from '../auth.js'
import { updateStatus, updateAvailable, showUpdate } from './updates.js'
import { currentTreeId } from '../trees.js'
import { gedcomExportUrl } from '../api/trees.js'
import { openChangeLog } from './changeLog.js'

/**
 * Datei-Menü. Besucher: nur Öffnen; Bearbeiter: + Export, Änderungsprotokoll; Admin: + Neu, Import, Verwandten-Baum.
 *   currentTree:   Ref des geöffneten Baums (Medienanzahl für „Export mit Medien“)
 *   relativesFrom: Getter der Person, aus deren Verwandten ein neuer Baum werden kann (oder null)
 *   on:            { open, new, import, relativesTree(person) }
 */
export function useFileMenu({ currentTree, relativesFrom, on }) {
  return computed(() => [
    { label: t('file.open'),         icon: 'pi pi-folder-open', command: on.open },
    { label: t('file.new'),          icon: 'pi pi-plus',        visible: isAdmin.value, command: on.new },
    { separator: true, visible: canEdit.value },
    { label: t('file.importGedcom'), icon: 'pi pi-upload',      visible: isAdmin.value, command: on.import },
    { label: t('file.exportGedcom'), icon: 'pi pi-download',    visible: canEdit.value, disabled: !currentTreeId.value,
      command: () => exportGedcom() },
    { label: t('file.exportGedcomMedia'), icon: 'pi pi-images', visible: canEdit.value, disabled: !currentTree.value?.media_count,
      command: () => exportGedcom({ media: true }) },
    { separator: true, visible: canEdit.value },
    { label: t('changes.menu'),     icon: 'pi pi-history',     visible: canEdit.value, disabled: !currentTreeId.value,
      command: () => openChangeLog() },
    { label: t('relativesTree.menu'), icon: 'pi pi-sitemap', visible: isAdmin.value,
      disabled: !relativesFrom()?.id, command: () => on.relativesTree(relativesFrom()) },
  ])
}

// Der Server liefert die Datei als Anhang – der Browser lädt sie herunter, ohne die Seite zu verlassen
function exportGedcom({ media = false } = {}) {
  const a = document.createElement('a')
  a.href = gedcomExportUrl(currentTreeId.value) + (media ? '?media=1' : '')
  a.download = ''
  document.body.appendChild(a)
  a.click()
  a.remove()
}

/** Benutzermenü (angemeldet).  on: { password, users, settings, logout } */
export function useUserMenu({ on }) {
  return computed(() => [
    { label: t('auth.changePassword'), icon: 'pi pi-key',      command: on.password },
    { label: t('auth.manageUsers'),    icon: 'pi pi-users',    visible: isAdmin.value, command: on.users },
    { label: t('settings.menu'),       icon: 'pi pi-cog',      visible: isAdmin.value, command: on.settings },
    { label: updateAvailable.value ? t('update.available', { version: updateStatus.value.latest }) : t('update.menu'),
      icon: updateAvailable.value ? 'pi pi-arrow-circle-up' : 'pi pi-sync', visible: isAdmin.value,
      class: updateAvailable.value ? 'menu-highlight' : undefined, command: () => { showUpdate.value = true } },
    { separator: true },
    { label: t('auth.logout'),         icon: 'pi pi-sign-out', command: on.logout },
    { separator: true, visible: !!appVersion.value },
    { label: 'Avunculus ' + appVersion.value, disabled: true, visible: !!appVersion.value, class: 'menu-version' },
  ])
}
