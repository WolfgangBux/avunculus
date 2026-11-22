import { request } from './http.js'

/** Installierte/neueste Version, ob automatisch installierbar (nur Admin; der Server fragt GitHub höchstens täglich) */
export const fetchUpdateStatus = (refresh = false) => request('GET', '/system/update' + (refresh ? '?refresh=1' : ''))
export const installUpdate     = (version)         => request('POST', '/system/update', { version })

/** Einstellungen der Installation (nur Admin): { imprint_url, privacy_url } */
export const fetchSettings = ()     => request('GET', '/system/settings')
export const saveSettings  = (data) => request('PUT', '/system/settings', data)
