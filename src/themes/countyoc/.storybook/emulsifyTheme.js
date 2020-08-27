// Documentation on theming Storybook: https://storybook.js.org/docs/configurations/theming/

import { create } from '@storybook/theming';

export default create({
  base: 'light',
  // Branding
  brandTitle: 'Promet Source',
  brandUrl: 'https://prometsource.com',
  brandImage: '/images/logo-promet.png',
});
