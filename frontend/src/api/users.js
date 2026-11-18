import { request } from './http.js'

export const fetchUsers = ()         => request('GET',    '/users')
export const createUser = (data)     => request('POST',   '/users', data)
export const updateUser = (id, data) => request('PUT',    `/users/${id}`, data)
export const deleteUser = (id)       => request('DELETE', `/users/${id}`)
