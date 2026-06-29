import axios from 'axios'

const getBaseURL = (): string => {
  const envUrl = import.meta.env.VITE_API_URL
  if (envUrl && typeof window !== 'undefined') {
    const isLocalEnv = envUrl.includes('localhost') || envUrl.includes('127.0.0.1')
    const isLocalHost = window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1'
    if (isLocalEnv && !isLocalHost) {
      return `${window.location.origin}/api`
    }
    return envUrl
  }
  if (typeof window !== 'undefined' && window.location.hostname !== 'localhost' && window.location.hostname !== '127.0.0.1') {
    return `${window.location.origin}/api`
  }
  return 'http://127.0.0.1:8000/api'
}

const api = axios.create({
  baseURL: getBaseURL(),
  headers: {
    'Accept': 'application/json',
    'Content-Type': 'application/json',
  },
})

api.interceptors.request.use((config) => {
  const token = localStorage.getItem('nessa_token')
  if (token) config.headers.Authorization = `Bearer ${token}`
  return config
})

api.interceptors.response.use(
  (res) => res,
  (error) => {
    if (error.response?.status === 401) {
      localStorage.removeItem('nessa_token')
      if (!window.location.pathname.includes('/login')) {
        window.location.assign('/login')
      }
    }
    return Promise.reject(error)
  },
)

export default api
