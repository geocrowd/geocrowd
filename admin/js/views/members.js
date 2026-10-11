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

  // Règle de double authentification de l'instance.
  const security = page.querySelector('[data-security]');
  const securityError = security.querySelector('[data-security-error]');
  const securitySaved = security.querySelector('[data-security-saved]');
  const flash = (el, text) => {
    el.textContent = text;
    el.hidden = false;
  };
  security.addEventListener('input', () => { securitySaved.hidden = true; });
  security.addEventListener('submit', (event) => {
    event.preventDefault();
    busy(security, async () => {
      try {
        showError(securityError, null);
        showSecurity(await api('PUT', 'security', { policy: security.elements.policy.value }));
        flash(securitySaved, 'Enregistré.');
        show(await api('GET', 'members'));
      } catch (e) {
        showError(securityError, e);
      }
    });
  });

  // Envoi d'e-mails : réglages du back-office, sauf si config.php les fixe.
  const mail = page.querySelector('[data-mail]');
  const mailError = mail.querySelector('[data-mail-error]');
  const mailSaved = mail.querySelector('[data-mail-saved]');
  const smtpBox = mail.querySelector('[data-smtp]');
  const toggleSmtp = () => { smtpBox.hidden = mail.elements.transport.value !== 'smtp'; };
  const mailErrors = (details = {}) => {
    for (const slot of mail.querySelectorAll('[data-error-for]')) {
      slot.textContent = details[slot.dataset.errorFor] ?? '';
    }
  };

  function showSecurity({ policy, mail: transport, mailSettings: settings }) {
    security.elements.policy.value = policy;
    fill(security, {
      mail: !transport
        ? 'Envoi d\'e-mails non configuré : seule l\'application d\'authentification est proposée.'
        : settings.tested
          ? 'Envoi d\'e-mails testé : les membres peuvent choisir le code par e-mail.'
          : 'Envoi d\'e-mails pas encore testé : le code par e-mail sera proposé après un e-mail de test réussi.',
    });
    const fromConfig = settings.source === 'config';
    fill(mail, {
      mailIntro: fromConfig
        ? 'Configuré dans config.php (clé « mail ») : ces réglages s\'y modifient.'
        : transport
          ? `Envoi configuré (${transport === 'smtp' ? 'serveur SMTP' : 'fonction mail()'}), ${settings.tested
            ? 'testé avec ces réglages. Sert aux codes de double authentification.'
            : 'pas encore testé : envoyez un e-mail de test pour proposer le code par e-mail aux membres.'}`
          : 'Envoi désactivé. Renseignez une adresse d\'expédition et un serveur SMTP pour proposer le code par e-mail.',
      passwordHint: settings.password_set ? 'Enregistré : laisser vide pour le garder.' : '',
    });
    for (const name of ['from', 'from_name', 'transport', 'host', 'user', 'secure']) {
      mail.elements[name].value = settings[name] ?? '';
    }
    mail.elements.port.value = settings.port ?? '';
    mail.elements.password.value = '';
    mail.querySelector('[data-mail-fields]').disabled = fromConfig;
    mail.querySelector('[type=submit]').hidden = fromConfig;
    mail.querySelector('[data-mail-off]').hidden = fromConfig || !transport;
    mail.querySelector('[data-test-mail]').hidden = !transport;
    toggleSmtp();
  }

  mail.elements.transport.addEventListener('change', toggleSmtp);
  mail.addEventListener('input', () => { mailSaved.hidden = true; });
  mail.addEventListener('submit', (event) => {
    event.preventDefault();
    busy(mail, async () => {
      try {
        showError(mailError, null);
        mailErrors();
        const port = mail.elements.port.value;
        showSecurity(await api('PUT', 'security/mail', {
          from: mail.elements.from.value,
          from_name: mail.elements.from_name.value,
          transport: mail.elements.transport.value,
          host: mail.elements.host.value,
          port: port === '' ? null : Number(port),
          secure: mail.elements.secure.value,
          user: mail.elements.user.value,
          password: mail.elements.password.value,
        }));
        flash(mailSaved, 'Enregistré. Envoyez un e-mail de test pour vérifier.');
      } catch (e) {
        showError(mailError, e);
        mailErrors(e.details);
      }
    });
  });
  mail.querySelector('[data-mail-off]').addEventListener('click', async () => {
    if (!confirm('Désactiver l\'envoi d\'e-mails ? Les membres qui utilisent le code par e-mail ne pourront plus se connecter qu\'avec un code de secours, jusqu\'à réactivation ou changement de méthode.')) {
      return;
    }
    try {
      showError(mailError, null);
      showSecurity(await api('DELETE', 'security/mail'));
      flash(mailSaved, 'Envoi désactivé.');
    } catch (e) {
      showError(mailError, e);
    }
  });
  mail.querySelector('[data-test-mail]').addEventListener('click', async (event) => {
    event.target.disabled = true;
    try {
      showError(mailError, null);
      const result = await api('POST', 'security/test-email');
      showSecurity(result);
      flash(mailSaved, `E-mail de test envoyé à ${result.sent}.`);
    } catch (e) {
      showError(mailError, e);
    } finally {
      event.target.disabled = false;
    }
  });

  showSecurity(await api('GET', 'security'));
  show(await api('GET', 'members'));
  el.append(page);
}
