// Quality-based pricing algorithm
// This file outlines the JavaScript function provided for reputation-backed pricing.
// Integration into the DataMonetization class or a similar module will be required.

/**
 * Calculates the price of data based on base price, user reputation, data quality, and freshness.
 *
 * @param {number} basePrice - The base price of the data type.
 * @param {number} userReputation - The user's reputation score (e.g., 0-100).
 * @param {number} dataQuality - A score representing the quality of the data (e.g., 0-10).
 * @param {number} dataTimestamp - The timestamp (milliseconds since epoch) when the data was generated or last validated.
 * @returns {number} The calculated price.
 */
function calculateDataPrice(basePrice, userReputation, dataQuality, dataTimestamp) {
  // Validate inputs
  if (typeof basePrice !== 'number' || basePrice < 0) {
    throw new Error("Base price must be a non-negative number.");
  }
  if (typeof userReputation !== 'number' || userReputation < 0) { // Assuming reputation is non-negative
    // Consider normalizing reputation if it's not on a 0-100 scale or similar
    console.warn("User reputation should ideally be a standardized score.");
  }
  if (typeof dataQuality !== 'number' || dataQuality < 0) { // Assuming quality is non-negative
     // Consider normalizing quality if it's not on a 0-10 scale or similar
    console.warn("Data quality should ideally be a standardized score.");
  }
  if (typeof dataTimestamp !== 'number' || dataTimestamp > Date.now()) {
      throw new Error("Invalid data timestamp.");
  }

  // Multiplier for user reputation. Example: reputation of 50 -> 1.5x multiplier.
  // Adjust the divisor (100) to change sensitivity.
  const repMultiplier = 1 + (userReputation / 100);

  // Multiplier for data quality. Example: quality of 5 -> 1.5x multiplier.
  // Adjust the divisor (10) to change sensitivity.
  const qualityMultiplier = 1 + (dataQuality / 10);

  // Freshness factor: Data less than 24 hours old gets a 20% bonus.
  // 86400000 milliseconds = 24 hours.
  const freshnessBonus = (Date.now() - dataTimestamp < 86400000) ? 1.2 : 1;

  const finalPrice = basePrice * repMultiplier * qualityMultiplier * freshnessBonus;

  // Ensure price is not negative and has a reasonable precision
  return Math.max(0, parseFloat(finalPrice.toFixed(8))); // Adjust toFixed for desired crypto precision
}

// Example Usage:
// const price = calculateDataPrice(0.02, 75, 8, Date.now() - (12 * 60 * 60 * 1000)); // 12 hours old data
// console.log("Calculated Data Price:", price);

/*
Integration Notes:
1.  **User Reputation System:**
    -   A system to calculate and store user reputation needs to be developed.
    -   Reputation could be based on factors like data accuracy, consistency, consent validity, etc.
    -   This might involve the Ceramic Network as mentioned in the deployment architecture.

2.  **Data Quality Assessment:**
    -   A mechanism to assess and score data quality is required.
    -   This could involve validation against schemas, freshness checks, and potentially ZKPs for verification.

3.  **Data Timestamping:**
    -   Ensure reliable timestamping of data when it's collected or created.

4.  **Integration with DataMarketplace:**
    -   The `createOffer` function in the `DataMarketplace.sol` smart contract currently takes a `_price`.
    -   This `calculateDataPrice` function should be called *before* `createOffer` to determine the price.
    -   The inputs (userReputation, dataQuality, dataTimestamp) need to be sourced from relevant systems.
    -   This might mean the `DataMonetization.js` class needs access to these parameters when calling `listDataOffer`.

5.  **Smart Contract Considerations:**
    -   The `calculateDataPrice` logic, being JavaScript, runs off-chain. The resulting price is then sent to the smart contract.
    -   If on-chain dynamic pricing based on these factors is desired, it would require oracle integration or more complex smart contract logic, which is significantly more involved. The current approach (off-chain calculation, on-chain fixed price per offer) is simpler.
*/

module.exports = { calculateDataPrice };
