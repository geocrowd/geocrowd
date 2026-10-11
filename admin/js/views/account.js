// Compte du membre connecté : double authentification (activation, changement, désactivation) et codes de secours.

import { api } from '../api.js';
import { busy, clone, fill, showError, ROLE_LABELS } from '../dom.js';
import { recoveryCodes } from './recovery.js';
import { emailSetup, totpSetup } from './two-factor.js';

const METHOD_LABELS = { totp: 'application d\'authentification', email: 'code par e-mail' };

export async function render(el, ctx) {
  const { user } = ctx.state.session;
  const page = clone('tpl-account');
  const slot = page.querySelector('[data-slot]');
  const passwordForm = page.querySelector('[data-password]');
  const passwordError = passwordForm.querySelector('[data-error]');
  const codesBox = page.querySelector('[data-codes-box]');
  let pendingAction = null;

  fill(page, { identity: `${user.name} · ${user.email} · ${ROLE_LABELS[user.role]}` });

  async function refresh() {
    const account = await api('GET', 'account');
    const enabled = Boolean(account.method);
    fill(page, {
      status: enabled
        ? `Activée : ${METHOD_LABELS[account.method]}.${account.required ? ' Obligatoire pour votre compte.' : ''}`
        : 'Désactivée : la connexion ne demande que le mot de passe.',
      remaining: account.recoveryCodes
        ? `Codes de secours restants : ${account.recoveryCodes}.`
        : 'Aucun code de secours restant : générez-en de nouveaux.',
    });
    const totp = page.querySelector('[data-enable="totp"]');
    const email = page.querySelector('[data-enable="email"]');
    email.hidden = account.method === 'email' || !account.mailEnabled;
    totp.textContent = { totp: 'Changer d\'application ou de téléphone', email: 'Passer à une application' }[account.method]
      ?? 'Activer avec une application';
    email.textContent = enabled ? 'Passer au code par e-mail' : 'Activer par e-mail';
    page.querySelector('[data-disable]').hidden = !enabled || account.required;
    page.querySelector('[data-recovery]').hidden = !enabled;
  }

  // Codes de secours affichés une seule fois, puis retour à l'état du compte.
  function showCodes(codes) {
    slot.hidden = true;
    passwordForm.hidden = true;
    codesBox.replaceChildren(recoveryCodes(codes, () => {
      codesBox.hidden = true;
      refresh();
    }));
    codesBox.hidden = false;
    page.querySelector('[data-recovery]').hidden = false;
  }

  // Configuration d'une méthode, après confirmation du mot de passe (exigée par le serveur).
  async function enable(method) {
    const done = async (result) => {
      await refresh();
      showCodes(result.recoveryCodes);
    };
    slot.replaceChildren(method === 'email' ? emailSetup(done) : await totpSetup(done));
    slot.hidden = false;
  }

  // Désactivation et nouveaux codes de secours : mot de passe redemandé.
  const askPassword = (action, confirm, label) => {
    slot.hidden = true;
    pendingAction = action;
    fill(passwordForm, { confirm, action: label });
    passwordForm.reset();
    showError(passwordError, null);
    passwordForm.hidden = false;
    passwordForm.elements.password.focus();
  };
  for (const button of page.querySelectorAll('[data-enable]')) {
    button.addEventListener('click', () => askPassword(`enable-${button.dataset.enable}`,
      'Confirmez votre mot de passe pour configurer la double authentification.', 'Continuer'));
  }
  page.querySelector('[data-disable]').addEventListener('click', () => askPassword('disable',
    'La connexion ne demandera plus que le mot de passe, et vos codes de secours seront supprimés.', 'Désactiver'));
  page.querySelector('[data-renew]').addEventListener('click', () => askPassword('renew',
    'Les codes de secours actuels ne fonctionneront plus.', 'Générer de nouveaux codes'));
  passwordForm.querySelector('[data-cancel]').addEventListener('click', () => { passwordForm.hidden = true; });

  passwordForm.addEventListener('submit', (event) => {
    event.preventDefault();
    busy(passwordForm, async () => {
      try {
        showError(passwordError, null);
        const body = { password: passwordForm.elements.password.value };
        if (pendingAction.startsWith('enable-')) {
          await api('POST', 'account/confirm-password', body);
          passwordForm.hidden = true;
          await enable(pendingAction.slice('enable-'.length));
        } else if (pendingAction === 'disable') {
          await api('POST', 'account/two-factor/disable', body);
          passwordForm.hidden = true;
          await refresh();
        } else {
          showCodes((await api('POST', 'account/recovery-codes', body)).codes);
        }
      } catch (e) {
        showError(passwordError, e);
      }
    });
  });

  await refresh();
  el.append(page);
}
