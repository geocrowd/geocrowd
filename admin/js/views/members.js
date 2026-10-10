// Membres du back-office, rôles et invitations (administrateurs uniquement).

import { api } from '../api.js';
import { busy, clone, fill, formatDate, showError, ROLE_LABELS } from '../dom.js';

export async function render(el, ctx) {
  const page = clone('tpl-members');
  const tbody = page.querySelector('tbody');
  const error = page.querySelector('[data-error]');
  const form = page.querySelector('form');
  const me = ctx.state.session.user;

  // Exécute une action sur un membre puis réaffiche la liste renvoyée par le serveur.
  const act = async (method, path, body) => {
    try {
      showError(error, null);
      show(await api(method, path, body));
    } catch (e) {
      showError(error, e);
      show(await api('GET', 'members'));
    }
  };

  function memberRow(user) {
    const row = fill(clone('tpl-member-row'), {
      name: user.name,
      email: user.email,
      last: user.last_login_at ? formatDate(user.last_login_at) : 'Jamais',
    });
    const role = row.querySelector('[data-role]');
    role.value = user.role;
    role.addEventListener('change', () => act('PATCH', `members/${user.id}`, { role: role.value }));

    const twoFactor = row.querySelector('[data-two-factor]');
    twoFactor.textContent = { totp: 'Application', email: 'E-mail' }[user.two_factor_method]
      ?? (user.two_factor_required ? 'À configurer' : 'Désactivée');
    twoFactor.className = user.two_factor ? 'badge badge-yes' : user.two_factor_required ? 'badge badge-pending' : 'badge';

    const isMe = user.id === me.id;
    row.querySelector('[data-self]').hidden = !isMe;
    const reset = row.querySelector('[data-reset]');
    reset.hidden = isMe || !user.two_factor;
    reset.addEventListener('click', () => {
      if (confirm(`Réinitialiser la double authentification de ${user.name} ? Ses codes de secours seront supprimés ; si la règle l'exige, la personne la configurera de nouveau à sa prochaine connexion.`)) {
        act('POST', `members/${user.id}/reset-two-factor`);
      }
    });
    const remove = row.querySelector('[data-remove]');
    remove.hidden = isMe;
    remove.addEventListener('click', () => {
      if (confirm(`Retirer ${user.name} du back-office ?`)) {
        act('DELETE', `members/${user.id}`);
      }
    });
    return row;
  }

  function invitationRow(invitation) {
    const row = fill(clone('tpl-invitation-row'), { email: invitation.email, role: ROLE_LABELS[invitation.role] });
    row.querySelector('[data-remove]').addEventListener('click', () => act('DELETE', `invitations/${invitation.id}`));
    return row;
  }

  function show({ users, invitations }) {
    tbody.replaceChildren(...users.map(memberRow), ...invitations.map(invitationRow));
    fill(page, { total: `${users.length} membre${users.length > 1 ? 's' : ''}` });
  }

  const inviteError = form.querySelector('[data-invite-error]');
  const linkBox = form.querySelector('[data-invite-link]');
  const linkInput = linkBox.querySelector('input');

  form.addEventListener('submit', (event) => {
    event.preventDefault();
    busy(form, async () => {
      try {
        showError(inviteError, null);
        const { token } = await api('POST', 'invitations', Object.fromEntries(new FormData(form)));
        linkInput.value = new URL(`#/invitation/${token}`, location.href).href;
        linkBox.hidden = false;
        linkBox.querySelector('[data-copy]').textContent = 'Copier';
        form.elements.email.value = '';
        show(await api('GET', 'members'));
      } catch (e) {
        showError(inviteError, e);
      }
    });
  });

  linkBox.querySelector('[data-copy]').addEventListener('click', async (event) => {
    await navigator.clipboard.writeText(linkInput.value);
    event.target.textContent = 'Copié';
  });

  // Règle de double authentification de l'instance et envoi d'e-mails.
  const security = page.querySelector('[data-security]');
  const securityError = security.querySelector('[data-security-error]');
  const securitySaved = security.querySelector('[data-security-saved]');
  const showSecurity = ({ policy, mail }) => {
    security.elements.policy.value = policy;
    fill(security, {
      mail: mail
        ? `Envoi d'e-mails configuré (${mail === 'smtp' ? 'serveur SMTP' : 'fonction mail() de PHP'}) : les membres peuvent choisir le code par e-mail.`
        : 'Envoi d\'e-mails non configuré (clé « mail » de config.php) : seule l\'application d\'authentification est proposée.',
    });
    security.querySelector('[data-test-mail]').hidden = !mail;
  };
  const securityMessage = (text) => {
    securitySaved.textContent = text;
    securitySaved.hidden = false;
  };
  security.addEventListener('input', () => { securitySaved.hidden = true; });
  security.addEventListener('submit', (event) => {
    event.preventDefault();
    busy(security, async () => {
      try {
        showError(securityError, null);
        showSecurity(await api('PUT', 'security', { policy: security.elements.policy.value }));
        securityMessage('Enregistré.');
        show(await api('GET', 'members'));
      } catch (e) {
        showError(securityError, e);
      }
    });
  });
  security.querySelector('[data-test-mail]').addEventListener('click', async (event) => {
    event.target.disabled = true;
    try {
      showError(securityError, null);
      const { sent } = await api('POST', 'security/test-email');
      securityMessage(`E-mail de test envoyé à ${sent}.`);
    } catch (e) {
      showError(securityError, e);
    } finally {
      event.target.disabled = false;
    }
  });

  showSecurity(await api('GET', 'security'));
  show(await api('GET', 'members'));
  el.append(page);
}
