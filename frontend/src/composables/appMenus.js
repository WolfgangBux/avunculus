import { computed } from 'vue'
import { t, locale, setLocale, availableLocales } from '../i18n/index.js'
import { isAdmin, canEdit, appVersion } from '../auth.js'
import { updateStatus, updateAvailable, showUpdate } from './updates.js'
import { currentTreeId } from '../trees.js'
import { gedcomExportUrl } from '../api/trees.js'
import { openChangeLog } from './changeLog.js'
import { showChecks } from './checks.js'
import { openReports } from './reports.js'

/**
 * Baum-Menü (Knopf mit dem Namen des geöffneten Baums). Alle: Öffnen; Bearbeiter: + Export;
 * Admin: + Neu, Import, Verwandten-Baum, öffentliche Freigabe des geöffneten Baums.
 *   currentTree:   Ref des geöffneten Baums (Medienanzahl für „Export mit Medien“)
 *   relativesFrom: Getter der Person, aus deren Verwandten ein neuer Baum werden kann (oder null)
 *   on:            { open, new, import, relativesTree(person), togglePublic }
 */
export function useTreeMenu({ currentTree, relativesFrom, on }) {
  return computed(() => [
    { label: t('file.open'),         icon: 'pi pi-folder-open', command: on.open },
    { label: t('file.new'),          icon: 'pi pi-plus',        visible: isAdmin.value, command: on.new },
    { separator: true, visible: canEdit.value },
    { label: t('file.importGedcom'), icon: 'pi pi-upload',      visible: isAdmin.value, command: on.import },
    { label: t('file.exportGedcom'), icon: 'pi pi-download',    visible: canEdit.value, disabled: !currentTreeId.value,
      command: () => exportGedcom() },
    { label: t('file.exportGedcomMedia'), icon: 'pi pi-images', visible: canEdit.value, disabled: !currentTree.value?.media_count,
      command: () => exportGedcom({ media: true }) },
    { separator: true, visible: isAdmin.value },
    { label: t(currentTree.value?.is_public ? 'privacy.makePrivate' : 'privacy.makePublic'),
      icon: currentTree.value?.is_public ? 'pi pi-lock' : 'pi pi-globe',
      visible: isAdmin.value && !!currentTree.value, command: on.togglePublic },
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

/** Benutzermenü (angemeldet) mit der Sprachwahl.  on: { password, users, settings, logout } */
export function useUserMenu({ on }) {
  return computed(() => [
    { label: t('auth.changePassword'), icon: 'pi pi-key',      command: on.password },
    { label: t('auth.manageUsers'),    icon: 'pi pi-users',    visible: isAdmin.value, command: on.users },
    { label: t('settings.menu'),       icon: 'pi pi-cog',      visible: isAdmin.value, command: on.settings },
    { label: updateAvailable.value ? t('update.available', { version: updateStatus.value.latest }) : t('update.menu'),
      icon: updateAvailable.value ? 'pi pi-arrow-circle-up' : 'pi pi-sync', visible: isAdmin.value,
      class: updateAvailable.value ? 'menu-highlight' : undefined, command: () => { showUpdate.value = true } },
    { separator: true },
    // Sprachwahl: die anderen Sprachen in ihrer eigenen Bezeichnung
    ...availableLocales.filter(l => l.value !== locale.value)
      .map(l => ({ label: l.name, icon: 'pi pi-globe', lang: l.value, command: () => setLocale(l.value) })),
    { separator: true },
    { label: t('auth.logout'),         icon: 'pi pi-sign-out', command: on.logout },
    { separator: true, visible: !!appVersion.value },
    { label: 'Avunculus ' + appVersion.value, disabled: true, visible: !!appVersion.value, class: 'menu-version' },
  ])
}

/**
 * Auswertung (Menü am Ende der Leiste): Statistik, Berichte (Untermenü), Verwandtschaftsrechner;
 * Bearbeiter: + Änderungsprotokoll, Fehlersuche.
 *   context(): { person, family } – was gerade angezeigt wird (zum Vorbelegen der Berichte)
 *   on:        { statistics, relationship }
 */
export function useAnalysisMenu({ context, on }) {
  const report = (kind, icon) => ({ label: t('reports.kinds.' + kind) + '…', icon, command: () => openReports(kind, context()) })
  return computed(() => {
    const noTree = !currentTreeId.value
    return [
      { label: t('nav.statistics'), icon: 'pi pi-chart-bar', disabled: noTree, command: on.statistics },
      { label: t('reports.menu'),   icon: 'pi pi-print',     disabled: noTree, items: [
        report('ancestors',   'pi pi-list'),
        report('pedigree',    'pi pi-sitemap'),
        report('descendants', 'pi pi-share-alt'),
        report('family',      'pi pi-users'),
        report('person',      'pi pi-id-card'),
        { separator: true },
        report('familybook',  'pi pi-book'),
        report('births',      'pi pi-star'),
        report('baptisms',    'pi pi-sun'),
        report('marriages',   'pi pi-heart'),
        report('deaths',      'pi pi-minus-circle'),
        report('occupations', 'pi pi-briefcase'),
        report('burials',     'pi pi-map-marker'),
      ] },
      { label: t('kinship.menu'),   icon: 'pi pi-share-alt', disabled: noTree, command: on.relationship },
      { separator: true, visible: canEdit.value },
      { label: t('changes.menu'),   icon: 'pi pi-history',   visible: canEdit.value, disabled: noTree, command: () => openChangeLog() },
      { label: t('checks.menu'),    icon: 'pi pi-exclamation-triangle', visible: canEdit.value, disabled: noTree,
        command: () => { showChecks.value = true } },
    ]
  })
}
