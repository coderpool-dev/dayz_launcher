'use strict';

const ServerBrowser = (() => {
  function categories(server) {
    return server.categories || { modes: [], style: server.modsCount ? 'modded' : 'vanilla', hardcore: false };
  }

  function filter(servers, filters, limit = 0) {
    const collection = filters.collection || 'all';
    const metric = collection === 'stable' ? 'activePercent' : collection;
    const matches = servers.filter(server => {
      const tags = categories(server);
      const population = server.population;
      return (!filters.map || (server.mapName || server.map) === filters.map)
        && (!filters.version || (server.version || 'Не указана') === filters.version)
        && (!filters.mode || (filters.mode === 'unknown' ? !tags.modes.length : tags.modes.includes(filters.mode)))
        && (!filters.style || tags.style === filters.style)
        && (!filters.perspective || server.perspective === filters.perspective)
        && (!filters.populated || (server.online !== false && server.players > 0))
        && (!filters.available || (server.online !== false && !server.password && server.players < server.maxPlayers))
        && (!filters.hardcore || tags.hardcore)
        && (collection === 'all' || (population?.ready && Number.isFinite(population[metric])));
    });
    if (collection !== 'all') matches.sort((a, b) => b.population[metric] - a.population[metric]
      || b.population.average - a.population.average || a.name.localeCompare(b.name, 'ru'));
    return { count: matches.length, servers: limit > 0 ? matches.slice(0, limit) : matches };
  }

  function facet(servers, field) {
    const counts = new Map();
    servers.forEach(server => {
      const value = field === 'map' ? server.mapName || server.map || 'Не указана' : server.version || 'Не указана';
      counts.set(value, (counts.get(value) || 0) + 1);
    });
    return Array.from(counts, ([value, count]) => ({ value, count })).sort((a, b) => a.value.localeCompare(b.value, 'ru', { numeric: true }));
  }

  return { filter, facet, categories };
})();
if (typeof module !== 'undefined') module.exports = ServerBrowser;
