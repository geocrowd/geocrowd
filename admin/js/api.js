// Accès à l'API du back-office.

let csrf = '';

export class ApiError extends Error {
  constructor(message, status, details = {}) {
    super(message);
    this.status = status;
    this.details = details;
  }
}

export function setCsrf(token) {
  csrf = token;
}

/** Appelle l'API ; `body` est un objet (envoyé en JSON) ou un FormData. */
export async function api(method, path, body) {
  const headers = { 'X-CSRF-Token': csrf };
  if (body && !(body instanceof FormData)) {
    headers['Content-Type'] = 'application/json';
    body = JSON.stringify(body);
  }

  const response = await fetch(`api/${path}`, { method, headers, body, credentials: 'same-origin' });
  const data = await response.json().catch(() => ({}));

  if (!response.ok) {
    if (response.status === 401) {
      window.dispatchEvent(new CustomEvent('unauthorized'));
    }
    throw new ApiError(data.error || 'Erreur de communication avec le serveur.', response.status, data.details);
  }
  return data;
}

/** URL d'une image enregistrée. */
export function fileUrl(name) {
  return `../api/files/${name}`;
}
