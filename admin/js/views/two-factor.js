// Formulaires d'activation de la double authentification, utilisés à la connexion et dans « Mon compte ».

import { api } from '../api.js';
import { busy, clone, fill, showError } from '../dom.js';

/** Erreur d'un code, avec son explication détaillée. */
export const codeError = (e) => (e.details?.code ? { message: `${e.message} ${e.details.code}` } : e);

/** Activation par application : QR code, puis premier code. `onDone(réponse)` reçoit les codes de secours. */
export async function totpSetup(onDone) {
  const { secret, uri } = await api('GET', 'two-factor/setup');
  const form = fill(clone('tpl-totp-setup'), { secret: secret.match(/.{1,4}/g).join(' ') });
  const error = form.querySelector('[data-error]');

  const qr = qrcode(0, 'M');
  qr.addData(uri);
  qr.make();
  form.querySelector('[data-qr]').innerHTML = qr.createSvgTag({ cellSize: 4, margin: 2, scalable: true });

  form.addEventListener('submit', (event) => {
    event.preventDefault();
    busy(form, async () => {
      try {
        showError(error, null);
        await onDone(await api('POST', 'two-factor/setup', { code: form.elements.code.value }));
      } catch (e) {
        showError(error, codeError(e));
        form.elements.code.select();
      }
    });
  });
  return form;
}

/** Activation par e-mail : envoi d'un code à l'adresse du compte, puis confirmation. */
export function emailSetup(onDone) {
  const form = clone('tpl-email-setup');
  const error = form.querySelector('[data-error]');
  const send = form.querySelector('[data-send]');
  const submit = form.querySelector('[type=submit]');

  send.addEventListener('click', async () => {
    send.disabled = true;
    try {
      showError(error, null);
      const { sent } = await api('POST', 'two-factor/email/send');
      fill(form, { sent: `Code envoyé à ${sent}, valable 10 minutes.` });
      form.elements.code.disabled = false;
      submit.disabled = false;
      send.textContent = 'Renvoyer le code';
      form.elements.code.focus();
    } catch (e) {
      showError(error, e);
    } finally {
      send.disabled = false;
    }
  });

  form.addEventListener('submit', (event) => {
    event.preventDefault();
    busy(form, async () => {
      try {
        showError(error, null);
        await onDone(await api('POST', 'two-factor/email/confirm', { code: form.elements.code.value }));
      } catch (e) {
        showError(error, codeError(e));
        form.elements.code.select();
      }
    });
  });
  return form;
}

/** Choix de la méthode quand l'envoi d'e-mails est configuré ; `onChoose('totp' | 'email')`. */
export function methodChoice(onChoose) {
  const choice = clone('tpl-two-factor-choice');
  for (const button of choice.querySelectorAll('[data-choose]')) {
    button.addEventListener('click', () => onChoose(button.dataset.choose));
  }
  return choice;
}
