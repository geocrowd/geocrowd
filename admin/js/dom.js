// Petits utilitaires DOM : les vues clonent des <template> de index.html et les remplissent.

export const STATUS_LABELS = { pending: 'En attente', published: 'Publié', rejected: 'Refusé' };
export const ROLE_LABELS = { admin: 'Admin', moderator: 'Modération' };

export function clone(id) {
  return document.getElementById(id).content.firstElementChild.cloneNode(true);
}

/** Remplit les éléments [data-text="clé"] avec les valeurs correspondantes. */
export function fill(root, data) {
  for (const el of root.querySelectorAll('[data-text]')) {
    if (el.dataset.text in data) {
      el.textContent = data[el.dataset.text] ?? '';
    }
  }
  return root;
}

export function statusBadge(el, status) {
  el.textContent = STATUS_LABELS[status];
  el.className = `badge badge-${status}`;
}

export function showError(el, error) {
  el.textContent = error?.message ?? '';
  el.hidden = !error;
}

export function formatDate(iso) {
  return iso ? new Date(iso).toLocaleString('fr-FR', { dateStyle: 'short', timeStyle: 'short' }) : '';
}

/** Désactive le bouton d'envoi pendant une action asynchrone. */
export async function busy(form, action) {
  const submit = form.querySelector('[type=submit]');
  submit.disabled = true;
  try {
    return await action();
  } finally {
    submit.disabled = false;
  }
}
