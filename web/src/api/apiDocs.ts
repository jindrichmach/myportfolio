import { api } from './client'

export type ParamIn = 'path' | 'query' | 'body' | 'form'
export type ParamType = 'integer' | 'boolean' | 'string' | 'date' | 'datetime' | 'decimal' | 'currency' | 'object' | 'file'

export interface ApiParam {
  name: string
  in: ParamIn
  type: ParamType
  required: boolean
  description: string
  enum: (string | number)[] | null
  default: unknown
  example: unknown
}

export interface ApiEndpoint {
  id: string
  group: string
  group_label: string
  method: 'GET' | 'POST' | 'PUT' | 'DELETE'
  path: string
  scope: 'read' | 'read_write'
  summary: string
  description: string | null
  params: ApiParam[]
  response: { status: number; description: string; example: unknown }
  content_type: 'application/json' | 'multipart/form-data' | 'text/csv'
}

export interface ApiCatalog {
  base_url: string
  version: string
  rate_limit: number
  groups: { key: string; label: string }[]
  endpoints: ApiEndpoint[]
}

export const apiDocs = {
  catalog: () => api.get<ApiCatalog>('/api-catalog').then(r => r.data),
  openApiUrl: '/api/openapi.json',
}
