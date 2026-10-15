import { trackFormSuccess } from '../tracking/index';
import { loadRecaptcha, recaptchaToken } from './recaptcha';

/**
 * Newsletter module in the layout (layout/_newsletter.antlers.html), posting to
 * NewsletterController, which sends the double opt-in mail; only its link
 * signs the visitor up in Shopify. The texts for every outcome are rendered in
 * the template.
 *
 * status: null | 'success' | 'invalid' (422, e.g. reCAPTCHA) | 'error' (mail, rate limit, network)
 */
export function initAlpineNewsletter() {
    Alpine.data('newsletterForm', () => ({
        sending: false,
        status: null,

        prepare() {
            loadRecaptcha();
        },

        async submit() {
            if (this.sending) return;

            this.sending = true;
            this.status = null;

            const form = this.$el;
            const data = new FormData(form);
            data.set('recaptcha_token', (await recaptchaToken('newsletter')) ?? '');

            try {
                const response = await fetch(form.getAttribute('action'), {
                    method: 'POST',
                    body: data,
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                });

                if (response.ok) {
                    this.status = 'success';
                    form.reset();
                    trackFormSuccess('newsletter');
                } else {
                    this.status = response.status === 422 ? 'invalid' : 'error';
                }
            } catch (error) {
                this.status = 'error';
            } finally {
                this.sending = false;
            }
        },
    }));
}
