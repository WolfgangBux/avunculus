<script>
import { ref, computed, watch, onMounted, defineComponent } from 'vue'
import { usePrimeVue } from 'openvue/config'
import Toast from 'openvue/toast'
import ConfirmDialog from 'openvue/confirmdialog'
import Menu from 'openvue/menu'
import { fetchTrees, setTreeStartPerson } from './api/trees.js'
import { currentTreeId, setCurrentTree, storedTreeId } from './trees.js'
import TreeOpenDialog     from './components/TreeOpenDialog.vue'
import TreeNewDialog      from './components/TreeNewDialog.vue'
import GedcomImportDialog from './components/GedcomImportDialog.vue'
import { fetchPerson }  from './api/persons.js'
import { fetchSource }  from './api/sources.js'
import { fetchRepository } from './api/repositories.js'
import { fetchPlace } from './api/places.js'
import { t, locale, setLocale, availableLocales } from './i18n/index.js'
import PersonForm  from './components/PersonForm.vue'
import PersonList  from './components/PersonList.vue'
import SourceForm  from './components/SourceForm.vue'
import SourceList  from './components/SourceList.vue'
import FamilyModal from './components/FamilyModal.vue'
import FamilyList   from './components/FamilyList.vue'
import FamilyEditor from './components/FamilyEditor.vue'
import RepositoryList from './components/RepositoryList.vue'
import RepositoryForm from './components/RepositoryForm.vue'
import TreeView    from './components/TreeView.vue'
import PlaceList   from './components/PlaceList.vue'
import PlaceForm   from './components/PlaceForm.vue'
import MediaList   from './components/MediaList.vue'
import MediaForm   from './components/MediaForm.vue'
import StatisticsView from './components/StatisticsView.vue'
import RelativesTreeDialog from './components/RelativesTreeDialog.vue'
import { fetchMedia } from './api/media.js'
import LoginDialog     from './components/LoginDialog.vue'
import PasswordDialog  from './components/PasswordDialog.vue'
import UserAdminDialog from './components/UserAdminDialog.vue'
import ChangeLogDialog from './components/ChangeLogDialog.vue'
import CheckDialog from './components/CheckDialog.vue'
import RelationshipDialog from './components/RelationshipDialog.vue'
import { openRelationship } from './composables/relationship.js'
import UpdateDialog from './components/UpdateDialog.vue'
import SettingsDialog from './components/SettingsDialog.vue'
import { checkUpdates, updateAvailable } from './composables/updates.js'
import { user, needsSetup, isAdmin, canEdit, loadMe, logout, userLabel, legalLinks } from './auth.js'
import { useNotify } from './composables/feedback.js'
import { useHashRoute } from './composables/hashRoute.js'
import { useFileMenu, useUserMenu } from './composables/appMenus.js'
import { SOURCE_URL, APP_VERSION, LICENSES_URL } from './about.js'

export default defineComponent({
  components: { PersonForm, PersonList, SourceForm, SourceList, FamilyModal, FamilyList, FamilyEditor, RepositoryList, RepositoryForm, PlaceList, PlaceForm, MediaList, MediaForm, StatisticsView, RelativesTreeDialog, TreeView, Toast, ConfirmDialog,
                Menu, TreeOpenDialog, TreeNewDialog, GedcomImportDialog, LoginDialog, PasswordDialog, UserAdminDialog, ChangeLogDialog, CheckDialog, RelationshipDialog, UpdateDialog, SettingsDialog },

  setup() {
    const view = ref('tree')

    // ── Datei: Stammbäume ─────────────────────────────────
    const trees       = ref([])
    const currentTree = computed(() => trees.value.find(tr => tr.id === currentTreeId.value) ?? null)
    const showOpen    = ref(false)
    const showNew     = ref(false)
    const showImport  = ref(false)
    const fileMenu    = ref(null)

    const fileMenuItems = useFileMenu({
      currentTree,
      // neuer Baum aus den Verwandten: die gerade im Personen-Tab geöffnete Person
      relativesFrom: () => view.value === 'persons' ? editingPerson.value : null,
      on: {
        open:   () => { showOpen.value = true },
        new:    () => { showNew.value = true },
        import: () => { showImport.value = true },
        // vorbelegt mit der gerade gezeigten Person und der Startperson
        relationship: () => openRelationship(
          view.value === 'persons' ? editingPerson.value?.id : view.value === 'tree' ? treeRootId.value : null, startPersonId.value),
        relativesTree: p => openRelativesTree(p),
      },
    })

    // ── Neuer Stammbaum aus den Verwandten einer Person ───
    const showRelatives   = ref(false)
    const relativesPerson = ref(null)
    function openRelativesTree(p) {
      if (!p?.id) return
      const n = p.names?.[0]
      relativesPerson.value = { id: Number(p.id), name: p.name ?? [n?.given_name, n?.surname_prefix, n?.surname].filter(Boolean).join(' ') }
      showRelatives.value = true
    }

    // ── Anmeldung ─────────────────────────────────────────
    const notify       = useNotify()
    const showLogin    = ref(false)
    const showPassword = ref(false)
    const showUsers    = ref(false)
    const showSettings = ref(false)
    const userMenu     = ref(null)
    const userMenuItems = useUserMenu({ on: {
      password: () => { showPassword.value = true },
      users:    () => { showUsers.value = true },
      settings: () => { showSettings.value = true },
      logout:   () => doLogout(),
    } })

    // Nach An-/Abmelden sieht man andere Bäume und andere Daten: alles neu laden
    async function afterAuthChange() {
      editingMedia.value = null; editingPlace.value = null; editingPerson.value = null
      editingSource.value = null; editingRepository.value = null; familyPerson.value = null
      editingFamilyId.value = null; creatingFamily.value = false
      showNew.value = false
      await loadTrees()
    }
    async function onLoggedIn(u) {
      notify.success(t('auth.loggedIn', { name: userLabel(u) }))
      await afterAuthChange()
    }
    async function doLogout() {
      try { await logout() } catch { /* Sitzung ohnehin weg */ }
      notify.success(t('auth.loggedOut'))
      await afterAuthChange()
    }
    // Server meldet 401 (Sitzung abgelaufen, Konto gelöscht …)
    window.addEventListener('avunculus:unauthorized', async () => {
      if (!user.value) return
      await loadMe()
      if (!user.value) { notify.error(t('auth.sessionExpired')); await afterAuthChange() }
    })

    async function loadTrees() {
      try { trees.value = await fetchTrees() } catch { trees.value = [] }
      const ids = trees.value.map(tr => tr.id)
      if (!ids.includes(currentTreeId.value)) {
        const stored = storedTreeId()
        setCurrentTree(ids.includes(stored) ? stored : ids[0] ?? null)
      }
    }

    function openTree(tree) {
      // derselbe Baum (z. B. nach Import hinein): Ansichten neu laden statt neu aufbauen
      if (tree.id === currentTreeId.value) {
        personRefresh.value++; familyRefresh.value++; sourceRefresh.value++; placeRefresh.value++; mediaRefresh.value++
      }
      editingMedia.value  = null
      editingPlace.value  = null
      editingPerson.value = null
      editingSource.value = null
      familyPerson.value  = null
      setCurrentTree(tree.id)
      loadTrees()
    }

    async function onTreeDeleted(tree) {
      if (tree.id === currentTreeId.value) setCurrentTree(null)
      await loadTrees()
    }

    // Beim Start: Position aus der Adresse übernehmen (Neuladen, Lesezeichen)
    onMounted(async () => {
      await loadMe()
      if (needsSetup.value) showLogin.value = true   // erster Start: Administrator anlegen
      const h = parseHash()
      if (h) setCurrentTree(h.tree)   // wird von loadTrees übernommen, wenn es den Baum gibt
      await loadTrees()
      await applyHash(h)
    })

    // Hauptperson beim Öffnen eines Baums: festgelegte Startperson, sonst die zuletzt gewählte
    // (im Browser gemerkt – nur Komfort, darf fehlen), sonst wählt der Server
    const rootKey    = () => 'avunculus.treeRoot.' + currentTreeId.value
    const treeRootId = ref(null)
    const startPersonId = computed(() => currentTree.value?.start_person_id ?? null)
    watch(() => currentTree.value?.id, () => {
      let stored = null
      try { stored = Number(localStorage.getItem(rootKey())) || null } catch { /* kein Storage */ }
      treeRootId.value = startPersonId.value ?? stored
    }, { immediate: true })

    // Startperson festlegen (Administratoren) – Baumliste aktualisieren, Ansicht bleibt
    async function setStartPerson(personId) {
      try {
        const tree = await setTreeStartPerson(currentTreeId.value, personId)
        trees.value = trees.value.map(tr => tr.id === tree.id ? tree : tr)
        notify.success(personId ? t('startPerson.saved') : t('startPerson.cleared'))
      } catch {
        notify.error(t('action.saveError'))
      }
    }
    watch(treeRootId, id => {
      try { if (id && currentTreeId.value) localStorage.setItem(rootKey(), String(id)) } catch { /* kein Storage */ }
    })

    // Vom Personen-Tab in den Stammbaum: die gerade gewählte Person wird Hauptperson
    function goTree() {
      if (view.value === 'persons' && editingPerson.value?.id) treeRootId.value = Number(editingPerson.value.id)
      view.value = 'tree'
    }
    // Vom Stammbaum zu den Personen: die Hauptperson wird zum Bearbeiten geöffnet
    async function goPersons() {
      const fromTree = view.value === 'tree' && treeRootId.value
      view.value = 'persons'
      if (fromTree && Number(editingPerson.value?.id) !== Number(treeRootId.value)) {
        await startEditPerson({ id: treeRootId.value }).catch(() => {})
      }
    }
    function showInTree(person) {
      treeRootId.value = Number(person.id)
      view.value = 'tree'
    }
    async function editFromTree(person) {
      view.value = 'persons'
      await startEditPerson(person)
    }
    function createFromTree() {
      editingPerson.value = null
      view.value = 'persons'
    }

    // PrimeVue-eigene Texte (Aria-Labels, leere Listen …) an die App-Sprache koppeln
    const primevue = usePrimeVue()
    // Admins sehen im Benutzermenü, wenn es eine neue Version gibt (der Server fragt höchstens täglich)
    watch(isAdmin, admin => { if (admin) checkUpdates() }, { immediate: true })

    watch(locale, () => {
      Object.assign(primevue.config.locale, {
        accept:             t('action.yes'),
        reject:             t('action.no'),
        emptyMessage:       t('common.empty'),
        emptySearchMessage: t('person.search.noMatch'),
      })
    }, { immediate: true })

    // ── Personen ──────────────────────────────────────────
    const personRefresh = ref(0)
    const editingPerson = ref(null)
    const familyPerson  = ref(null)

    async function startEditPerson(person) {
      editingPerson.value = await fetchPerson(person.id)
    }
    // Automatisch gespeichert: die Fassung vom Server wird zur bearbeiteten Person (auch eine eben
    // angelegte); shown = false, wenn das Formular inzwischen schon eine andere Person zeigt
    function onPersonSaved(person, shown = true) {
      personRefresh.value++
      if (shown && person?.id) editingPerson.value = person
    }
    function onCancelEditPerson() { editingPerson.value = null }

    function openFamily(person) { familyPerson.value = person }
    // Beziehungsdialog aus dem Personenformular: Hauptname für die Anzeige übernehmen
    function openFamilyFor(person) {
      familyPerson.value = { ...(person.names?.[0] ?? {}), ...person, id: person.id }
    }
    function closeFamily()      { familyPerson.value = null }
    function onFamilyChanged()  { personRefresh.value++; familyRefresh.value++ }

    // ── Familien ──────────────────────────────────────────
    const familyRefresh   = ref(0)
    const editingFamilyId = ref(null)
    const creatingFamily  = ref(false)

    function selectFamily(id)  { editingFamilyId.value = id; creatingFamily.value = false }
    function startNewFamily()  { editingFamilyId.value = null; creatingFamily.value = true }
    function onFamilyEdited()  { familyRefresh.value++; personRefresh.value++ }
    function onFamilyCreated(id) {
      creatingFamily.value  = false
      editingFamilyId.value = id
      onFamilyEdited()
    }
    function onFamilyDeleted(id) {
      if (editingFamilyId.value === id) editingFamilyId.value = null
      onFamilyEdited()
    }
    function openFamilyEditor(id) {
      familyPerson.value = null
      selectFamily(id)
      view.value = 'families'
    }

    // ── Quellen ───────────────────────────────────────────
    const sourceRefresh = ref(0)
    const editingSource = ref(null)

    async function startEditSource(source) {
      editingSource.value = await fetchSource(source.id)
    }
    // Automatisch gespeichert: die Fassung vom Server wird zur bearbeiteten Quelle (auch eine eben angelegte);
    // shown = false, wenn das Formular inzwischen schon eine andere zeigt
    function onSourceSaved(source, shown = true) {
      sourceRefresh.value++
      if (shown) editingSource.value = source
    }
    function onCancelEditSource() { editingSource.value = null }

    // ── Archive (REPO) ────────────────────────────────────
    const repoRefresh       = ref(0)
    const editingRepository = ref(null)

    async function startEditRepository(repo) {
      editingRepository.value = await fetchRepository(repo.id)
    }
    function onRepositorySaved(repo, shown = true) {
      repoRefresh.value++
      sourceRefresh.value++          // Quellenliste zeigt den Archivnamen
      if (shown) editingRepository.value = repo
    }
    function onRepositoryDeleted(id) {
      if (editingRepository.value?.id === id) editingRepository.value = null
      sourceRefresh.value++
    }
    // Querverweise Quelle ⇄ Archiv
    async function openRepositoryFromSource(id) {
      view.value = 'repositories'
      await startEditRepository({ id })
    }
    async function openSourceFromRepository(id) {
      view.value = 'sources'
      await startEditSource({ id })
    }

    // ── Orte ──────────────────────────────────────────────
    const placeRefresh = ref(0)
    const editingPlace = ref(null)

    async function selectPlace(id) {
      editingPlace.value = await fetchPlace(id)
    }
    async function openPlaceFromStats(id) {
      view.value = 'places'
      await selectPlace(id)
    }
    function onPlaceSaved(place, shown = true) {
      if (shown) editingPlace.value = place
      placeRefresh.value++
    }
    // Unterort/neuer Ort angelegt oder zusammengeführt: dorthin wechseln
    function onPlaceChanged(place) {
      editingPlace.value = place
      placeRefresh.value++
    }
    function onPlaceDeleted(place) {
      editingPlace.value = null
      placeRefresh.value++
    }

    // ── Medien ────────────────────────────────────────────
    const mediaRefresh = ref(0)
    const editingMedia = ref(null)

    async function selectMedia(id) {
      editingMedia.value = await fetchMedia(id)
    }
    function onMediaChanged(m) {
      if (m) editingMedia.value = m
      mediaRefresh.value++
    }
    function onMediaDeleted() {
      editingMedia.value = null
      mediaRefresh.value++
      personRefresh.value++   // Hauptbilder in Listen
      loadTrees()             // Medienanzahl (Export-Menü)
    }
    async function openPersonFromMedia(id) {
      view.value = 'persons'
      await startEditPerson({ id })
    }
    async function openSourceFromMedia(id) {
      view.value = 'sources'
      await startEditSource({ id })
    }

    // ── Position in der Adresse: #/<Baum>/<Ansicht>[/<ID>] (siehe composables/hashRoute.js) ──
    // Jeder Tab steht hier genau einmal: welche ID er in der Adresse merkt und wie er sie
    // wiederherstellt. Ein neuer Tab braucht nur einen weiteren Eintrag.
    const TABS = {
      tree:     { id: () => treeRootId.value,        restore: id => { if (id) treeRootId.value = id } },
      persons:  { id: () => editingPerson.value?.id,
                  restore: id => id ? startEditPerson({ id }).catch(() => { editingPerson.value = null }) : (editingPerson.value = null) },
      families: { id: () => editingFamilyId.value,   restore: id => selectFamily(id) },
      sources:  { id: () => editingSource.value?.id,
                  restore: id => id ? startEditSource({ id }).catch(() => { editingSource.value = null }) : (editingSource.value = null) },
      repositories: { id: () => editingRepository.value?.id,
                      restore: id => id ? startEditRepository({ id }).catch(() => { editingRepository.value = null }) : (editingRepository.value = null) },
      places:   { id: () => editingPlace.value?.id,
                  restore: id => id ? selectPlace(id).catch(() => { editingPlace.value = null }) : (editingPlace.value = null) },
      media:    { id: () => editingMedia.value?.id,
                  restore: id => id ? selectMedia(id).catch(() => { editingMedia.value = null }) : (editingMedia.value = null) },
      statistics: { id: () => null, restore: () => {} },
    }
    const { parseHash, applyHash } = useHashRoute({ view, tabs: TABS, trees })

    return {
      updateAvailable,
      SOURCE_URL, APP_VERSION, LICENSES_URL,
      t, locale, setLocale, availableLocales,
      user, needsSetup, isAdmin, canEdit, userLabel, showLogin, showPassword, showUsers, showSettings, legalLinks, userMenu, userMenuItems, onLoggedIn,
      view, treeRootId, startPersonId, openRelationship, setStartPerson, goTree, goPersons, showInTree, editFromTree, createFromTree,
      trees, currentTree, currentTreeId, showOpen, showNew, showImport, fileMenu, fileMenuItems,
      loadTrees, openTree, onTreeDeleted,
      personRefresh, editingPerson, startEditPerson, onPersonSaved, onCancelEditPerson, showRelatives, relativesPerson, openRelativesTree,
      familyPerson, openFamily, openFamilyFor, closeFamily, onFamilyChanged,
      familyRefresh, editingFamilyId, creatingFamily, selectFamily, startNewFamily,
      onFamilyEdited, onFamilyCreated, onFamilyDeleted, openFamilyEditor,
      sourceRefresh, editingSource, startEditSource, onSourceSaved, onCancelEditSource,
      repoRefresh, editingRepository, startEditRepository, onRepositorySaved, onRepositoryDeleted,
      openRepositoryFromSource, openSourceFromRepository,
      placeRefresh, editingPlace, selectPlace, openPlaceFromStats, onPlaceSaved, onPlaceChanged, onPlaceDeleted,
      mediaRefresh, editingMedia, selectMedia, onMediaChanged, onMediaDeleted, openPersonFromMedia, openSourceFromMedia,
    }
  },
})
</script>

<template>
<header class="app-header">
  <div class="brand">
    <img class="brand-logo" :src="'favicon.svg'" alt="" aria-hidden="true" />
    <div class="brand-text">
      <h1>Avunculus</h1>
      <span class="tagline">{{ t('app.tagline') }}</span>
    </div>
  </div>

  <nav class="app-nav">
    <button class="nav-tab nav-menu" aria-haspopup="true" aria-controls="file-menu" @click="fileMenu.toggle($event)">
      {{ t('file.menu') }} <i class="pi pi-angle-down" />
    </button>
    <Menu id="file-menu" ref="fileMenu" :model="fileMenuItems" popup />
    <span class="nav-divider" aria-hidden="true" />
    <button :class="['nav-tab', { active: view === 'tree' }]" @click="goTree">{{ t('nav.tree') }}</button>
    <button :class="['nav-tab', { active: view === 'persons' }]" @click="goPersons">{{ t('nav.persons') }}</button>
    <button :class="['nav-tab', { active: view === 'families' }]" @click="view = 'families'">{{ t('nav.families') }}</button>
    <button :class="['nav-tab', { active: view === 'sources' }]" @click="view = 'sources'">{{ t('nav.sources') }}</button>
    <button :class="['nav-tab', { active: view === 'repositories' }]" @click="view = 'repositories'">{{ t('nav.repositories') }}</button>
    <button :class="['nav-tab', { active: view === 'places' }]" @click="view = 'places'">{{ t('nav.places') }}</button>
    <button :class="['nav-tab', { active: view === 'media' }]" @click="view = 'media'">{{ t('nav.media') }}</button>
    <button :class="['nav-tab', { active: view === 'statistics' }]" @click="view = 'statistics'">{{ t('nav.statistics') }}</button>
  </nav>

  <button v-if="currentTree" class="current-tree" v-tooltip.bottom="currentTree.name + ' – ' + t('file.open')" :aria-label="currentTree.name + ' – ' + t('file.open')" @click="showOpen = true">
    <i class="pi pi-sitemap" />
    <span>{{ currentTree.name }}</span>
  </button>

  <button v-if="user" class="user-btn" :title="userLabel(user)" :aria-label="userLabel(user)" aria-haspopup="true" aria-controls="user-menu" @click="userMenu.toggle($event)">
    <i :class="['pi', isAdmin ? 'pi-shield' : 'pi-user']" />
    <span>{{ userLabel(user) }}</span>
    <i class="pi pi-angle-down" />
    <span v-if="updateAvailable" class="update-dot" :title="t('update.dot')" />
  </button>
  <button v-else class="user-btn" @click="showLogin = true">
    <i class="pi pi-sign-in" />
    <span>{{ t('auth.login') }}</span>
  </button>
  <Menu id="user-menu" ref="userMenu" :model="userMenuItems" popup />

  <div class="lang-switcher">
    <button
      v-for="l in availableLocales" :key="l.value"
      :class="['lang-btn', { active: locale === l.value }]"
      @click="setLocale(l.value)"
    >{{ l.label }}</button>
  </div>
</header>

<div v-if="!user && currentTreeId" class="readonly-banner">
  <i class="pi pi-eye" /> {{ t('auth.readOnly') }}
</div>

<main v-if="!user && !currentTreeId && !needsSetup" class="app-main">
  <div class="card empty-hint public-empty">
    <i class="pi pi-lock" />
    <p><strong>{{ t('auth.noPublicTrees') }}</strong></p>
    <p>{{ t('auth.noPublicTreesHint') }}</p>
    <button class="user-btn" @click="showLogin = true"><i class="pi pi-sign-in" /> {{ t('auth.login') }}</button>
  </div>
</main>

<main v-if="currentTreeId" :key="currentTreeId + '/' + (user?.role ?? 'guest')" class="app-main">
  <TreeView
    v-if="view === 'tree'"
    :root-id="treeRootId"
    :start-id="startPersonId"
    @set-start="setStartPerson"
    :refresh="personRefresh"
    @select-root="id => treeRootId = id"
    @edit="editFromTree"
    @family="openFamily"
    @create="createFromTree"
  />

  <div v-if="view === 'persons'" class="workspace">
    <PersonList
      :refresh="personRefresh"
      :selected-id="editingPerson?.id ?? null"
      :selected-person="editingPerson"
      @edit="startEditPerson"
      @family="openFamily"
      @tree="showInTree"
      @create="onCancelEditPerson"
    />
    <PersonForm
      v-if="canEdit || editingPerson"
      :edit-person="editingPerson"
      :start-person-id="startPersonId"
      :refresh="personRefresh"
      @set-start="setStartPerson"
      @person-saved="onPersonSaved"
      @edit-person="id => startEditPerson({ id })"
      @edit-family="openFamilyEditor"
      @relations="openFamilyFor"
      @relatives-tree="openRelativesTree"
      @relationship="id => openRelationship(id, startPersonId)"
      @open-place="openPlaceFromStats"
    />
    <div v-else class="card empty-hint">
      <i class="pi pi-user" />
      <p>{{ t('person.selectHint') }}</p>
    </div>
  </div>

  <div v-if="view === 'families'" class="workspace">
    <FamilyList
      :refresh="familyRefresh"
      :selected-id="editingFamilyId"
      @select="selectFamily"
      @create="startNewFamily"
      @deleted="onFamilyDeleted"
    />
    <FamilyEditor
      v-if="editingFamilyId || creatingFamily"
      :family-id="editingFamilyId"
      :creating="creatingFamily"
      @changed="onFamilyEdited"
      @created="onFamilyCreated"
      @deleted="onFamilyDeleted"
      @cancel="creatingFamily = false"
      @show-person="m => editFromTree({ id: m.person_id })"
    />
    <div v-else class="card empty-hint">
      <i class="pi pi-users" />
      <p>{{ t('families.selectHint') }}</p>
    </div>
  </div>

  <div v-if="view === 'sources'" class="workspace">
    <SourceList :refresh="sourceRefresh" :selected-id="editingSource?.id ?? null" :selected-source="editingSource"
      @edit="startEditSource" @create="onCancelEditSource" />
    <SourceForm
      v-if="canEdit || editingSource"
      :edit-source="editingSource"
      @source-saved="onSourceSaved"
      @open-repository="openRepositoryFromSource"
    />
    <div v-else class="card empty-hint">
      <i class="pi pi-book" />
      <p>{{ t('source.selectHint') }}</p>
    </div>
  </div>

  <div v-if="view === 'repositories'" class="workspace">
    <RepositoryList :refresh="repoRefresh" :selected-id="editingRepository?.id ?? null"
      @edit="startEditRepository" @create="editingRepository = null" @deleted="onRepositoryDeleted" />
    <RepositoryForm v-if="canEdit || editingRepository" :edit-repository="editingRepository"
      @saved="onRepositorySaved" @open-source="openSourceFromRepository" />
    <div v-else class="card empty-hint">
      <i class="pi pi-building" />
      <p>{{ t('repository.selectHint') }}</p>
    </div>
  </div>

  <div v-if="view === 'places'" class="workspace">
    <PlaceList :refresh="placeRefresh" :selected-id="editingPlace?.id ?? null" @select="selectPlace" @created="onPlaceChanged"
      @bulk-updated="editingPlace && selectPlace(editingPlace.id)"
      @bulk-deleted="ids => editingPlace && !ids.has(editingPlace.id) && (editingPlace = null)" />
    <PlaceForm v-if="editingPlace" :place="editingPlace"
      @saved="onPlaceSaved" @select="selectPlace" @created="onPlaceChanged" @merged="onPlaceChanged" @deleted="onPlaceDeleted" />
    <div v-else class="card empty-hint">
      <i class="pi pi-map-marker" />
      <p>{{ t('place.selectHint') }}</p>
    </div>
  </div>

  <StatisticsView v-if="view === 'statistics'" @open-person="openPersonFromMedia" @open-place="openPlaceFromStats" />

  <div v-if="view === 'media'" class="workspace workspace-media">
    <MediaList :refresh="mediaRefresh" :selected-id="editingMedia?.id ?? null" @select="selectMedia"
      @uploaded="m => { selectMedia(m.id); loadTrees() }" />
    <MediaForm v-if="editingMedia" :media="editingMedia"
      @saved="onMediaChanged" @deleted="onMediaDeleted"
      @open-person="openPersonFromMedia" @open-family="openFamilyEditor" @open-source="openSourceFromMedia" />
    <div v-else class="card empty-hint">
      <i class="pi pi-images" />
      <p>{{ t('media.selectHint') }}</p>
    </div>
  </div>
</main>

<footer class="app-footer">
  <span>Avunculus {{ APP_VERSION }}</span>
  <a :href="SOURCE_URL" target="_blank" rel="noopener">{{ t('app.source') }}</a>
  <a :href="SOURCE_URL + '/blob/main/LICENSE'" target="_blank" rel="noopener">{{ t('app.license') }}</a>
  <a :href="LICENSES_URL" target="_blank" rel="noopener">{{ t('app.thirdParty') }}</a>
  <a v-if="legalLinks.imprint_url" :href="legalLinks.imprint_url" target="_blank" rel="noopener">{{ t('app.imprint') }}</a>
  <a v-if="legalLinks.privacy_url" :href="legalLinks.privacy_url" target="_blank" rel="noopener">{{ t('app.privacy') }}</a>
</footer>

<FamilyModal
  v-if="familyPerson"
  :person="familyPerson"
  @close="closeFamily"
  @changed="onFamilyChanged"
  @edit-family="openFamilyEditor"
/>

<TreeOpenDialog v-model:visible="showOpen" :current-id="currentTreeId"
  @open="openTree" @changed="loadTrees" @deleted="onTreeDeleted" />
<LoginDialog v-model:visible="showLogin" :setup-mode="needsSetup" @done="onLoggedIn" />
<PasswordDialog v-model:visible="showPassword" />
<UserAdminDialog v-if="isAdmin" v-model:visible="showUsers" />
<SettingsDialog v-if="isAdmin" v-model:visible="showSettings" />
<ChangeLogDialog v-if="canEdit" />
<CheckDialog v-if="canEdit" />
<RelationshipDialog />
<UpdateDialog v-if="isAdmin" />
<TreeNewDialog v-model:visible="showNew" @created="openTree" />
<RelativesTreeDialog v-model:visible="showRelatives" :person="relativesPerson" @open="tree => { loadTrees(); openTree(tree) }" />
<GedcomImportDialog v-model:visible="showImport" :current-tree-id="currentTreeId" @imported="loadTrees" @open="openTree" />

<Toast position="bottom-right" />
<ConfirmDialog :style="{ width: '26rem' }" />

</template>
