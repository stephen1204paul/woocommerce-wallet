# WooCommerce Wallet

A comprehensive wallet system for WooCommerce that allows customers to add funds to their wallet and use those funds for purchases.

## Features

### 1. Wallet Balance Management
- Each customer has their own wallet with a balance
- Real-time balance tracking
- Multi-currency support (uses WooCommerce currency)

### 2. Top-up Functionality
- Customers can add funds to their wallet
- Configurable minimum and maximum top-up amounts
- Uses standard WooCommerce payment gateways for top-ups
- Automatic credit after successful payment

### 3. Wallet Payment Gateway
- Pay for orders using wallet balance
- Shows current balance and remaining balance during checkout
- Prevents wallet payment for wallet top-up orders
- Sufficient balance validation

### 4. Transaction History
- Complete transaction log for all wallet activities
- Shows credits (top-ups, refunds) and debits (purchases)
- Displays balance after each transaction
- Formatted date and amount display

### 5. Admin Features
- Wallet settings page in WooCommerce
- Manually adjust customer wallet balances
- View wallet statistics (total balance, users, transactions)
- Per-user wallet management on user profile page
- Comprehensive transaction history page with filtering and search
- View all wallet transactions across all users
- Filter by user, transaction type, and date range
- Search by order ID or transaction details
- Pagination for large transaction datasets

### 6. Automatic Refunds
- Automatic refund to wallet for cancelled orders
- Refund support for partial and full refunds
- Transaction records for all refunds

### 7. Cashback Rewards System
- Role-based cashback percentages
- Automatic cashback crediting on order completion
- Configurable cashback calculation (include/exclude shipping and taxes)
- Maximum cashback limit per order
- Cashback info displayed on wallet page
- Excludes wallet top-up orders from cashback

## Installation

1. Upload the `woocommerce-wallet` folder to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to WooCommerce → Wallet to configure settings
4. Configure the Wallet payment gateway in WooCommerce → Settings → Payments

## Configuration

### General Settings
Navigate to **WooCommerce → Wallet** to configure:

- **Enable Wallet**: Turn the wallet system on/off
- **Minimum Top-up Amount**: Set the minimum amount customers can add (default: 10)
- **Maximum Top-up Amount**: Set the maximum amount customers can add (default: 10000)

### Cashback Settings
Navigate to **WooCommerce → Wallet** (Cashback Settings section) to configure:

- **Enable Cashback**: Turn the cashback system on/off
- **Maximum Cashback Per Order**: Set a maximum cashback amount per order (0 for unlimited)
- **Include Shipping in Cashback**: Whether to include shipping costs in cashback calculation
- **Include Taxes in Cashback**: Whether to include taxes in cashback calculation
- **Cashback Percentage by User Role**: Set different cashback percentages for each user role
  - Examples: Customer (5%), Subscriber (3%), Wholesale (10%)
  - If a user has multiple roles, the highest percentage is used

### Payment Gateway Settings
Navigate to **WooCommerce → Settings → Payments → Wallet** to configure:

- **Enable/Disable**: Enable wallet as a payment method
- **Title**: Payment method title shown to customers
- **Description**: Description shown during checkout
- **Order Button Text**: Text for the checkout button

## Usage

### For Customers

#### Adding Funds
1. Go to **My Account → My Wallet**
2. Enter the desired top-up amount
3. Click "Proceed to Payment"
4. Complete payment using any available payment gateway
5. Funds are automatically credited after successful payment

#### Making a Purchase
1. Add products to cart
2. Proceed to checkout
3. Select "Wallet" as the payment method
4. Your current balance and remaining balance will be displayed
5. Complete the order

#### Viewing Transactions
1. Go to **My Account → My Wallet**
2. Scroll to "Transaction History"
3. View all credits and debits with details

#### Earning Cashback
1. Cashback is automatically credited to your wallet after order completion
2. View your cashback rate on the **My Wallet** page
3. Cashback appears in transaction history as a credit
4. Cashback rate depends on your user role (set by admin)

### For Administrators

#### Adjusting Customer Wallet
1. Go to **Users → All Users**
2. Click "Edit" on any user
3. Scroll to "Wallet Information" section
4. Enter adjustment amount (positive to credit, negative to debit)
5. Add an optional note
6. Click "Update User"

#### Viewing Statistics
1. Go to **WooCommerce → Wallet**
2. View statistics including:
   - Total wallet balance across all users
   - Number of users with balance
   - Total transactions
   - Total credits and debits

#### Viewing All Transactions
1. Go to **WooCommerce → Wallet Transactions**
2. View all wallet transactions across all users
3. Use filters to narrow results:
   - Filter by specific user
   - Filter by transaction type (Credit/Debit)
   - Filter by date range
   - Search by order ID or transaction details
4. Click on user names to view their profile
5. Click on order numbers to view order details
6. View today's statistics at the top (total transactions, credits, debits)

## Database Structure

The plugin creates two database tables:

### wp_wc_wallet_transactions
Stores all wallet transactions (credits and debits)
- `id`: Transaction ID
- `user_id`: WordPress user ID
- `type`: Transaction type (credit/debit)
- `amount`: Transaction amount
- `balance`: Balance after transaction
- `currency`: Transaction currency
- `details`: Transaction description
- `order_id`: Related WooCommerce order ID (if applicable)
- `created_date`: Transaction timestamp

### wp_wc_wallet_balance
Stores current wallet balance for each user
- `id`: Record ID
- `user_id`: WordPress user ID
- `balance`: Current balance
- `currency`: Balance currency
- `updated_date`: Last update timestamp

## Hooks and Filters

### Actions

```php
// Fired when wallet is credited
do_action('wc_wallet_credited', $user_id, $amount, $new_balance, $transaction_id);

// Fired when wallet is debited
do_action('wc_wallet_debited', $user_id, $amount, $new_balance, $transaction_id);
```

### Filters

```php
// Modify available payment gateways
apply_filters('woocommerce_available_payment_gateways', $available_gateways);
```

## Requirements

- WordPress 5.8 or higher
- WooCommerce 5.0 or higher
- PHP 7.4 or higher

## Support

For support, please open an issue on the GitHub repository or contact the plugin author.

## Changelog

### Version 1.0.0
- Initial release
- Wallet balance management
- Top-up functionality
- Wallet payment gateway
- Transaction history
- Admin features
- Automatic refunds

## License

This plugin is licensed under the GPL v2 or later.

## Credits

Developed for WooCommerce store owners who want to provide wallet functionality to their customers.
