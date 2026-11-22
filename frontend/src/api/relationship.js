import { request } from './http.js'

/**
 * Verwandtschaft zwischen zwei Personen des aktuellen Baums:
 * { a, b, relations: [{ type, path: [{ id, rel }], ga?, gb?, half?, ancestors?, side?, via? }], persons }
 */
export const fetchRelationship = (a, b) => request('GET', `/relationship?a=${Number(a)}&b=${Number(b)}`)
