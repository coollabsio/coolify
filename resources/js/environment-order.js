const ORDER = ['production', 'staging', 'development'];

const rank = name => {
  const index = ORDER.indexOf(String(name).toLowerCase());
  return index === -1 ? ORDER.length : index;
};

/**
 * Sort order of environment names: production, staging, and development first, then the others by name.
 * Environment::compareNames() has the same order for the project page.
 */
export const compareEnvironmentNames = (a, b) => rank(a) - rank(b) || String(a).localeCompare(String(b));
