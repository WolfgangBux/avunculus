import { request } from './http.js'

export const fetchRepositories = ()         => request('GET',    '/repositories')
export const fetchRepository   = (id)       => request('GET',    `/repositories/${id}`)
export const createRepository  = (data)     => request('POST',   '/repositories', data)
export const updateRepository  = (id, data) => request('PUT',    `/repositories/${id}`, data)
export const deleteRepository  = (id)       => request('DELETE', `/repositories/${id}`)
