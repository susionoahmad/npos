type LaravelErrorResponse = {
  message?: string
  errors?: Record<string, string[]>
}

export function pickErrorMessage(err: any, fallback = 'Terjadi kesalahan') {
  return (
    err?.response?.data?.message ||
    err?.message ||
    (typeof err?.response?.data === 'string' ? err.response.data : null) ||
    fallback
  )
}

export function pickFieldErrors(err: any): Record<string, string[]> {
  const data = err?.response?.data as LaravelErrorResponse | undefined
  if (!data?.errors || typeof data.errors !== 'object') return {}
  return data.errors
}

