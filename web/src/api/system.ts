import { api } from './client'

export const systemApi = {
  version: () => api.get<{ current: string }>('/version').then(r => r.data),
}
