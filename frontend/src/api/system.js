import { request } from './http.js'

/** Installierte/neueste Version, ob automatisch installierbar (nur Admin; der Server fragt GitHub höchstens täglich) */
export const fetchUpdateStatus = (refresh = false) => request('GET', '/system/update' + (refresh ? '?refresh=1' : ''))
export const installUpdate     = (version)         => request('POST', '/system/update', { version })
