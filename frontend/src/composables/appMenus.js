import { computed } from 'vue'
import { t } from '../i18n/index.js'
import { isAdmin, canEdit, appVersion } from '../auth.js'
import { updateStatus, updateAvailable, showUpdate } from './updates.js'
import { currentTreeId } from '../trees.js'
import { gedcomExportUrl } from '../api/trees.js'
import { openChangeLog } from './changeLog.js'
import { showChecks } from './checks.js'
import { openReports } from './reports.js'

/**
 * Datei-Menü. Besucher: Öffnen, Verwandtschaftsrechner; Bearbeiter: + Export, Änderungsprotokoll, Fehlersuche; Admin: + Neu, Import, Verwandten-Baum.
 *   currentTree:   Ref des geöffneten Baums (Medienanzahl für „Export mit Medien“)
 *   relativesFrom: Getter der Person, aus deren Verwandten ein neuer Baum werden kann (oder null)
 *   on:            { open, new, import, relationship, relativesTree(person) }
 */
export function useFileMenu({ currentTree, relativesFrom, on }) {
  return computed(() => [
    { label: t('file.open'),         icon: 'pi pi-folder-open', command: on.open },
    { label: t('file.new'),          icon: 'pi pi-plus',        visible: isAdmin.value, command: on.new },
    { label: t('kinship.menu'),       icon: 'pi pi-share-alt',   disabled: !currentTreeId.value, command: on.relationship },
    { separator: true, visible: canEdit.value },
    { label: t('file.importGedcom'), icon: 'pi pi-upload',      visible: isAdmin.value, command: on.import },
    { label: t('file.exportGedcom'), icon: 'pi pi-download',    visible: canEdit.value, disabled: !currentTreeId.value,
      command: () => exportGedcom() },
    { label: t('file.exportGedcomMedia'), icon: 'pi pi-images', visible: canEdit.value, disabled: !currentTree.value?.media_count,
      command: () => exportGedcom({ media: true }) },
    { separator: true, visible: canEdit.value },
    { label: t('changes.menu'),     icon: 'pi pi-history',     visible: canEdit.value, disabled: !currentTreeId.value,
      command: () => openChangeLog() },
    { label: t('checks.menu'),      icon: 'pi pi-exclamation-triangle', visible: canEdit.value, disabled: !currentTreeId.value,
      command: () => { showChecks.value = true } },
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

/**
 * Berichte-Menü: Blätter zu einer Person/Familie und Listen; jeder Eintrag öffnet den Berichtsdialog
 * mit dieser Art. context(): { person, family } – was gerade angezeigt wird (zum Vorbelegen).
 */
export function useReportsMenu({ context }) {
  const item = (kind, icon) => ({ label: t('reports.kinds.' + kind) + '…', icon, disabled: !currentTreeId.value,
                                  command: () => openReports(kind, context()) })
  return computed(() => [
    item('ancestors',   'pi pi-sitemap'),
    item('descendants', 'pi pi-share-alt'),
    item('family',      'pi pi-users'),
    item('person',      'pi pi-id-card'),
    { separator: true },
    item('births',      'pi pi-star'),
    item('baptisms',    'pi pi-sun'),
    item('marriages',   'pi pi-heart'),
    item('deaths',      'pi pi-minus-circle'),
    item('occupations', 'pi pi-briefcase'),
    item('burials',     'pi pi-map-marker'),
  ])
}
