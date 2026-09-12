export type ApiErrorPayload = {
  error?: {
    code?: string
    message?: string
    request_id?: string
    [key: string]: unknown
  }
}

export type AdminIdentity = {
  type?: string
  user_id?: string
  user?: {
    id?: string
    email?: string
    name?: string
    roles?: string[]
  }
  scopes?: string[]
  token_id?: string
}

export type ContentTypeField = {
  handle: string
  type: string
  label: string
  required?: boolean
  localized?: boolean
  settings?: Record<string, unknown>
}

export type ContentTypeRecord = {
  id?: string
  handle: string
  name: string
  fields: ContentTypeField[]
  blocks?: Array<Record<string, unknown>>
  localized?: boolean
  revisionable?: boolean
  storage?: string
  created_at?: string
  updated_at?: string
}

export type ContentEntryRecord = {
  id: string
  type?: string
  content_type?: string
  title?: string
  slug?: string
  status?: string
  locale?: string
  data?: Record<string, unknown>
  revision_number?: number
  created_at?: string
  updated_at?: string
  published_at?: string | null
}

export type DashboardPayload = {
  cards?: Array<{ label: string; value: string | number | boolean; status?: string }>
  content?: Record<string, unknown>
  security?: Record<string, unknown>
  runtime?: Record<string, unknown>
}
