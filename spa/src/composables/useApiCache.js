// composables/useApiCache.js

export function useApiCache() {
  /**
   * Fetches data from an API, utilizing localStorage caching with expiry.
   *
   * @param {string} cacheKey A unique key for localStorage (e.g., 'modules_data', 'user_profile')
   * @param {Function} fetchFunction The async function that performs the API call (e.g., () => api.get('/endpoint'))
   * @returns {Promise<any | null>} The fetched or cached data
   */
  const fetchDataWithCache = async (cacheKey, fetchFunction, ttl = 10000, refresh = false) => {
    if (refresh === true) {
      localStorage.removeItem(cacheKey)
    }

    const cachedItemString = localStorage.getItem(cacheKey)
    const now = new Date()

    // 5mins = 300000
    // 30sec = 30000
    // 5sec = 5000

    // --- 1. Check Cache ---
    if (cachedItemString) {
      try {
        const cachedItem = JSON.parse(cachedItemString)
        const isCacheValid = now.getTime() - cachedItem.timestamp < ttl

        if (isCacheValid) {
          console.log(`[${cacheKey}] Using valid cached data.`)
          return cachedItem.data // Return cached data immediately
        } else {
          console.log(`[${cacheKey}] Cache expired. Fetching new data.`)
          localStorage.removeItem(cacheKey)
        }
      } catch (e) {
        console.error(`[${cacheKey}] Error parsing cached data; clearing cache.`, e)
        localStorage.removeItem(cacheKey)
      }
    }

    // --- 2. Fetch from API (if cache invalid or missing) ---
    try {
      // Assuming fetchFunction returns a response object (e.g. axios { data: ... })
      const response = await fetchFunction()
      const freshData = response.data || response // Handle both axios response.data and raw data

      console.log(`[${cacheKey}] Fetched fresh data from API.`)

      // --- 3. Save new data to cache ---
      const itemToCache = {
        data: freshData,
        timestamp: now.getTime(),
      }
      localStorage.setItem(cacheKey, JSON.stringify(itemToCache))

      return freshData
    } catch (error) {
      console.error(`[${cacheKey}] Error fetching data:`, error)
      // You might want to rethrow the error so the calling component can handle the UI state (e.g., error message)
      throw error
    }
  }

  // Optional: A helper function to manually clear a specific cache item
  const clearCache = (cacheKey) => {
    localStorage.removeItem(cacheKey)
    console.log(`[${cacheKey}] Cache cleared manually.`)
  }

  return {
    fetchDataWithCache,
    clearCache,
  }
}
