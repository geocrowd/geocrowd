// Journal des actions du back-office (administrateurs).

import { api } from '../api.js';
import { clone, fill, formatDate, ROLE_LABELS, STATUS_LABELS } from '../dom.js';

const PER_PAGE = 50;

const ACTIONS = {
  'account.setup': 'Création du premier compte',
  login: 'Connexion',
  'login.failed': 'Mot de passe refusé',
  'two_factor.enabled': 'Double authentification activée',
  'two_factor.failed': 'Code de connexion refusé',
  'two_factor.disabled': 'Double authentification désactivée',
  'security.policy': 'Règle de double authentification modifiée',
  'security.test_email': 'E-mail de test envoyé',
  'recovery_codes.renewed': 'Nouveaux codes de secours',
  'invitation.created': 'Invitation créée',
  'invitation.cancelled': 'Invitation annulée',
  'invitation.accepted': 'Invitation acceptée',
  'member.role': 'Rôle modifié',
  'member.removed': 'Membre retiré',
  'member.two_factor_reset': 'Double authentification réinitialisée',
  'collection.created': 'Collection créée',
  'collection.updated': 'Collection modifiée',
  'collection.deleted': 'Collection supprimée',
  'point.created': 'Point ajouté',
  'point.updated': 'Point modifié',
  'point.status': 'Statut modifié',
  'point.deleted': 'Point supprimé',
  'edit.applied': 'Proposition appliquée',
  'edit.rejected': 'Proposition refusée',
  'front.updated': 'Site public modifié',
  'key.created': 'Clé d\'API créée',
  'key.revoked': 'Clé d\'API révoquée',
};

/** Détails lisibles : changement de statut, rôle, champs retirés… */
function describe(details) {
  if (!details) return '';
  const parts = [];
  if (details.from || details.to) parts.push(`${STATUS_LABELS[details.from] ?? details.from} → ${STATUS_LABELS[details.to] ?? details.to}`);
  else if (details.status) parts.push(STATUS_LABELS[details.status] ?? details.status);
  if (details.role) parts.push(ROLE_LABELS[details.role] ?? details.role);
  if (details.removed_fields) parts.push(`champs retirés : ${details.removed_fields.join(', ')}`);
  if ('enabled' in details) parts.push(details.enabled ? 'activé' : 'désactivé');
  if (details.prefix) parts.push(`${details.prefix}…`);
  if (details.method) parts.push({ totp: 'application', email: 'e-mail' }[details.method] ?? details.method);
  if (details.policy) parts.push({ optional: 'facultative', admins: 'obligatoire pour les admins', all: 'obligatoire pour tous' }[details.policy] ?? details.policy);
  return parts.join(' · ');
}

export async function render(el) {
  let page = 1;
  const panel = clone('tpl-audit');
  const tbody = panel.querySelector('tbody');

  async function load() {
    const data = await api('GET', `audit?page=${page}`);
    const pages = Math.max(1, Math.ceil(data.total / PER_PAGE));
    tbody.replaceChildren(...data.items.map((entry) => fill(clone('tpl-audit-row'), {
      date: formatDate(entry.created_at),
      user: entry.user_name ?? '—',
      action: ACTIONS[entry.action] ?? entry.action,
      target: entry.target,
      details: describe(entry.details),
    })));
    fill(panel, { total: `${data.total} entrée${data.total > 1 ? 's' : ''}`, page: `${page} / ${pages}` });
    panel.querySelector('[data-pager]').hidden = pages === 1;
    panel.querySelector('[data-prev]').disabled = page === 1;
    panel.querySelector('[data-next]').disabled = page === pages;
  }

  panel.querySelector('[data-prev]').addEventListener('click', () => { page -= 1; load(); });
  panel.querySelector('[data-next]').addEventListener('click', () => { page += 1; load(); });

  await load();
  el.append(panel);
}
