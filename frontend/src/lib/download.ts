import { http } from './http'

/** Télécharge un fichier renvoyé par l'API (export Excel / PDF) en conservant le nom fourni par le serveur. */
export async function download(url: string, params: Record<string, unknown> = {}, fallbackName = 'export.xlsx') {
  const res = await http.get<Blob>(url, { params, responseType: 'blob' })
  const disposition = String(res.headers['content-disposition'] ?? '')
  const name = /filename\*?=(?:UTF-8'')?"?([^";]+)"?/i.exec(disposition)?.[1] ?? fallbackName
  const href = URL.createObjectURL(res.data)
  const a = Object.assign(document.createElement('a'), { href, download: decodeURIComponent(name) })
  document.body.appendChild(a)
  a.click()
  a.remove()
  setTimeout(() => URL.revokeObjectURL(href), 1000)
}
