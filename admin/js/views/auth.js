// Connexion, double authentification, création du premier compte et acceptation d'une invitation.

import { api } from '../api.js';
import { busy, clone, fill, showError, ROLE_LABELS } from '../dom.js';
import { recoveryCodes } from './recovery.js';
import { codeError, emailSetup, methodChoice, totpSetup } from './two-factor.js';

/**
 * Monte un formulaire qui envoie ses champs à `path` ; la session renvoyée est passée
 * à `done` (par défaut, elle remplace la session courante).
 */
function mount(el, ctx, template, path, data = {}, done = ctx.setSession) {
  const form = fill(clone(template), data);
  const error = form.querySelector('[data-error]');

  form.addEventListener('submit', (event) => {
    event.preventDefault();
    busy(form, async () => {
      try {
        showError(error, null);
        await done(await api('POST', path, Object.fromEntries(new FormData(form))));
      } catch (e) {
        showError(error, e.details?.code ? { message: `${e.message} ${e.details.code}` } : e);
        form.querySelector('.input-code')?.select();
      }
    });
  });

  form.querySelector('[data-cancel]')?.addEventListener('click', async () => {
    ctx.setSession(await api('POST', 'logout'));
  });

  el.append(form);
  form.querySelector('input').focus();
  return form;
}

export function login(el, ctx) {
  mount(el, ctx, 'tpl-login', 'login');
}

export function setup(el, ctx) {
  if (!ctx.state.session.needsSetup) {
    return ctx.navigate('#/login');
  }
  mount(el, ctx, 'tpl-setup', 'setup');
}

export async function invitation(el, ctx) {
  const token = ctx.param;
  const { email, role } = await api('GET', `invitations/${token}`);
  mount(el, ctx, 'tpl-invitation', `invitations/${token}/accept`, { email, role: ROLE_LABELS[role] });
}

/**
 * Après le mot de passe : code de double authentification (application, e-mail ou code de secours),
 * ou configuration quand la règle de l'instance l'exige et que le compte n'en a pas.
 */
export async function twoFactor(el, ctx) {
  const session = ctx.state.session;
  const card = clone('tpl-auth-card');
  const slot = card.querySelector('[data-slot]');
  card.querySelector('[data-cancel]').addEventListener('click', async () => {
    ctx.setSession(await api('POST', 'logout'));
  });

  if (session.twoFactor === 'verify') {
    const email = session.twoFactorMethod === 'email';
    fill(card, {
      subtitle: email
        ? `Saisissez le code à 6 chiffres envoyé à ${session.emailHint}, ou l'un de vos codes de secours.`
        : 'Saisissez le code à 6 chiffres affiché par votre application d\'authentification, ou l\'un de vos codes de secours.',
    });
    const form = clone('tpl-code-form');
    const error = form.querySelector('[data-error]');
    const resend = form.querySelector('[data-resend]');
    resend.hidden = !email;
    if (session.emailError) {
      showError(error, { message: session.emailError });
    }
    resend.addEventListener('click', async () => {
      resend.disabled = true;
      try {
        showError(error, null);
        const { sent } = await api('POST', 'two-factor/email/send');
        fill(form, { resent: `Nouveau code envoyé à ${sent}.` });
      } catch (e) {
        showError(error, e);
      } finally {
        resend.disabled = false;
      }
    });
    form.addEventListener('submit', (event) => {
      event.preventDefault();
      busy(form, async () => {
        try {
          showError(error, null);
          await ctx.setSession(await api('POST', 'two-factor/verify', { code: form.elements.code.value }));
        } catch (e) {
          showError(error, codeError(e));
          form.elements.code.select();
        }
      });
    });
    slot.append(form);
    el.append(card);
    form.elements.code.focus();
    return;
  }

  // Configuration imposée : codes de secours affichés une seule fois, avant d'entrer dans le back-office.
  const done = (result) => {
    const box = fill(clone('tpl-auth-card'), { subtitle: 'Double authentification activée. Voici vos codes de secours.' });
    box.querySelector('[data-cancel]').remove();
    box.querySelector('[data-slot]').append(recoveryCodes(result.recoveryCodes, () => ctx.setSession(result)));
    el.replaceChildren(box);
  };
  const show = async (method) => {
    slot.replaceChildren(method === 'email' ? emailSetup(done) : await totpSetup(done));
    slot.querySelector('input:not([disabled]), [data-send]')?.focus();
  };
  fill(card, { subtitle: 'Ce compte doit être protégé par une double authentification : chaque connexion demandera, après le mot de passe, un code à 6 chiffres.' });
  el.append(card);
  if (session.mailEnabled) {
    slot.append(methodChoice(show));
  } else {
    await show('totp');
  }
}
