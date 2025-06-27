// SPDX-License-Identifier: GPL-3.0
pragma solidity ^0.8.0;

contract DataMarketplace {
    struct DataOffer {
        address owner;
        string dataType;
        uint256 price; // Price in Wei
        string cid; // IPFS content ID
        bool active;
        address buyer; // To track who bought it, if applicable (for non-exclusive sales this might be a list or event based)
    }

    mapping(uint256 => DataOffer) public offers;
    uint256 public offerCount;

    address public platformWallet; // Address to receive platform fees
    uint256 public platformFeePercentage; // e.g., 10 for 10%

    event NewOffer(uint256 indexed offerId, address indexed owner, string dataType, uint256 price, string cid);
    event Purchase(uint256 indexed offerId, address indexed buyer, uint256 pricePaid);
    event OfferDeleted(uint256 indexed offerId, address indexed owner);
    event OfferDeactivated(uint256 indexed offerId, address indexed owner);

    constructor(address _platformWallet, uint256 _platformFeePercentage) {
        require(_platformWallet != address(0), "Platform wallet cannot be zero address");
        require(_platformFeePercentage <= 100, "Platform fee cannot exceed 100%");
        platformWallet = _platformWallet;
        platformFeePercentage = _platformFeePercentage;
        offerCount = 0;
    }

    function createOffer(
        string memory _dataType,
        uint256 _price, // Price in Wei
        string memory _cid
    ) public {
        require(_price > 0, "Price must be greater than zero");
        require(bytes(_dataType).length > 0, "Data type cannot be empty");
        require(bytes(_cid).length > 0, "CID cannot be empty");

        offerCount++;
        offers[offerCount] = DataOffer({
            owner: msg.sender,
            dataType: _dataType,
            price: _price,
            cid: _cid,
            active: true,
            buyer: address(0) // No buyer initially
        });
        emit NewOffer(offerCount, msg.sender, _dataType, _price, _cid);
    }

    function purchaseOffer(uint256 _offerId) public payable {
        DataOffer storage offer = offers[_offerId];

        require(_offerId > 0 && _offerId <= offerCount, "Invalid offer ID");
        require(offer.active, "Offer not available or already sold");
        require(msg.value >= offer.price, "Insufficient payment sent");
        require(offer.owner != msg.sender, "Owner cannot buy their own offer");

        uint256 feeAmount = (offer.price * platformFeePercentage) / 100;
        uint256 ownerAmount = offer.price - feeAmount;

        // Transfer funds
        payable(offer.owner).transfer(ownerAmount);
        payable(platformWallet).transfer(feeAmount);

        // Handle refund if more than required was sent
        if (msg.value > offer.price) {
            payable(msg.sender).transfer(msg.value - offer.price);
        }

        offer.active = false; // Mark as sold/inactive
        offer.buyer = msg.sender; // Record the buyer

        emit Purchase(_offerId, msg.sender, offer.price);
    }

    // GDPR-compliant data deletion (logical deletion, actual data on IPFS is immutable)
    // This function makes the offer inaccessible through the marketplace.
    // True data deletion from IPFS is complex and depends on node pinning.
    function deleteOffer(uint256 _offerId) public {
        require(_offerId > 0 && _offerId <= offerCount, "Invalid offer ID");
        DataOffer storage offer = offers[_offerId];
        require(offer.owner == msg.sender, "Not the owner of the offer");
        // Instead of using `delete` which can leave gaps and is costly,
        // we mark it as inactive and clear sensitive fields if necessary.
        // For GDPR, the main point is to stop processing/offering the data.
        offer.active = false;
        // Optionally clear CID if required by specific interpretation of "deletion"
        // offer.cid = ""; // This means data can't be found via contract even if CID known elsewhere
        emit OfferDeactivated(_offerId, msg.sender); // Or a more specific OfferLogicallyDeleted event
    }

    function getOffer(uint256 _offerId) public view returns (address owner, string memory dataType, uint256 price, string memory cid, bool active, address buyer) {
        require(_offerId > 0 && _offerId <= offerCount, "Invalid offer ID");
        DataOffer storage offer = offers[_offerId];
        return (offer.owner, offer.dataType, offer.price, offer.cid, offer.active, offer.buyer);
    }

    function updatePlatformWallet(address _newWallet) public {
        // Add appropriate access control (e.g., only contract owner)
        // For simplicity, omitting Ownable or similar patterns here
        require(msg.sender == platformWallet, "Only current platform wallet can update"); // Simplified access control
        require(_newWallet != address(0), "New wallet cannot be zero address");
        platformWallet = _newWallet;
    }

    function updatePlatformFee(uint256 _newFeePercentage) public {
        // Add appropriate access control
        require(msg.sender == platformWallet, "Only current platform wallet can update"); // Simplified access control
        require(_newFeePercentage <= 100, "New fee cannot exceed 100%");
        platformFeePercentage = _newFeePercentage;
    }
}
