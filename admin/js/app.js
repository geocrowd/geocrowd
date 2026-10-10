// Point d'entrée du back-office : session, navigation et routage par hash.

import { api, setCsrf } from './api.js';
import { clone, fill, ROLE_LABELS } from './dom.js';
import * as map from './map.js';
import * as auth from './views/auth.js';
import * as list from './views/list.js';
import * as point from './views/point.js';
import * as collections from './views/collections.js';
import * as schema from './views/schema.js';
import * as members from './views/members.js';
import * as edits from './views/edits.js';
import * as keys from './views/keys.js';
import * as account from './views/account.js';
import * as site from './views/site.js';
import * as audit from './views/audit.js';

const view = document.getElementById('view');
const topbar = document.getElementById('topbar');

// Données partagées entre les vues.
const state = { session: null, collections: [], counts: {} };

// [motif, vue, accès] : 'guest' (non connecté), 'pending' (mot de passe vérifié, code attendu),
// 'user' (modérateur ou admin), 'admin'.
const routes = [
  [/^\/login$/, auth.login, 'guest'],
  [/^\/setup$/, auth.setup, 'guest'],
  [/^\/invitation\/(\w+)$/, auth.invitation, 'guest'],
  [/^\/two-factor$/, auth.twoFactor, 'pending'],
  [/^\/moderation$/, (el, ctx) => list.render(el, ctx, 'pending', 'À modérer'), 'user'],
  [/^\/points$/, (el, ctx) => list.render(el, ctx, 'published', 'Points'), 'user'],
  [/^\/points\/(new|\d+)$/, point.render, 'user'],
  [/^\/edits$/, edits.list, 'user'],
  [/^\/edits\/(\d+)$/, edits.detail, 'user'],
  [/^\/account$/, account.render, 'user'],
  [/^\/collections$/, collections.render, 'admin'],
  [/^\/collections\/(new|[a-z0-9_-]+)$/, schema.render, 'admin'],
  [/^\/members$/, members.render, 'admin'],
  [/^\/keys$/, keys.render, 'admin'],
  [/^\/site$/, site.render, 'admin'],
  [/^\/audit$/, audit.render, 'admin'],
];

const ctx = {
  state,
  navigate: (hash) => { location.hash = hash; },
  refreshCollections,
  // Nouvelle session renvoyée par le serveur (connexion, étape de double authentification, déconnexion).
  async setSession(session) {
    applySession(session);
    if (session.user) {
      await refreshCollections();
      location.hash = '#/moderation';
    } else {
      location.hash = session.twoFactor ? '#/two-factor' : '#/login';
    }
  },
};

function applySession(session) {
  state.session = session;
  setCsrf(session.csrf);

  const user = session.user;
  topbar.hidden = !user;
  if (user?.role === 'admin') {
    checkExposure();
  }
  if (user) {
    topbar.querySelector('[data-user-name]').textContent = user.name;
    const role = topbar.querySelector('[data-user-role]');
    role.textContent = ROLE_LABELS[user.role];
    role.className = user.role === 'admin' ? 'badge badge-admin' : 'badge';
    for (const link of topbar.querySelectorAll('[data-admin]')) {
      link.hidden = user.role !== 'admin';
    }
  }
}

/** Alerte si la base par défaut se télécharge depuis le web (.htaccess ignoré, Nginx mal configuré). */
async function checkExposure() {
  const response = await fetch('../data/geocrowd.sqlite', { method: 'HEAD', cache: 'no-store' }).catch(() => null);
  document.getElementById('exposure').hidden = !response?.ok;
}

async function refreshCollections() {
  const data = await api('GET', 'collections');
  state.collections = data.collections;
  state.counts = data.counts;
  const pending = Object.values(data.counts).reduce((sum, c) => sum + (c.pending ?? 0), 0);
  topbar.querySelector('[data-pending]').textContent = pending ? `· ${pending}` : '';
  topbar.querySelector('[data-edits]').textContent = data.edits ? `· ${data.edits}` : '';
}

let cleanup = null;

async function route() {
  const path = location.hash.slice(1) || '/moderation';
  const match = routes.map(([pattern, render, access]) => [path.match(pattern), render, access]).find(([m]) => m);
  if (!match) {
    return ctx.navigate('#/moderation');
  }
  const [[, param], render, access] = match;
  const { user, twoFactor, needsSetup } = state.session;

  if (access === 'pending' && !twoFactor) {
    return ctx.navigate(user ? '#/moderation' : '#/login');
  }
  if (!['guest', 'pending'].includes(access) && !user) {
    return ctx.navigate(needsSetup ? '#/setup' : twoFactor ? '#/two-factor' : '#/login');
  }
  if ((access === 'guest' && user) || (access === 'admin' && user.role !== 'admin')) {
    return ctx.navigate('#/moderation');
  }

  if (user) {
    // Compteurs du bandeau (points en attente, propositions) à jour à chaque page.
    refreshCollections().catch(() => {});
  }
  cleanup?.();
  map.clear();
  for (const link of topbar.querySelectorAll('[data-nav]')) {
    link.classList.toggle('active', path.startsWith(`/${link.dataset.nav}`));
  }

  // Conteneur propre à cette page (display: contents) : une page encore en chargement quand
  // on en change écrit dans un conteneur déjà retiré, et n'apparaît plus sous la nouvelle.
  const slot = document.createElement('div');
  slot.className = 'contents';
  view.replaceChildren(slot);
  try {
    cleanup = await render(slot, { ...ctx, param });
  } catch (error) {
    slot.replaceChildren(fill(clone('tpl-error'), { message: error.message }));
  }
}

document.getElementById('logout').addEventListener('click', async () => {
  ctx.setSession(await api('POST', 'logout'));
});

document.getElementById('search').addEventListener('submit', async (event) => {
  event.preventDefault();
  const input = event.target.elements.q;
  input.setCustomValidity((await map.search(input.value)) ? '' : 'Lieu introuvable.');
  input.reportValidity();
});

document.getElementById('search').addEventListener('input', (event) => event.target.setCustomValidity(''));

// Session expirée, membre retiré ou double authentification réinitialisée pendant la navigation.
window.addEventListener('unauthorized', async () => {
  if (!state.session.user && !state.session.twoFactor) {
    return;
  }
  applySession(await api('GET', 'session'));
  route();
});

window.addEventListener('hashchange', route);

map.init(document.getElementById('map'));
applySession(await api('GET', 'session'));
if (state.session.user) {
  await refreshCollections();
}
route();
