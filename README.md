# Paystack Plugin for Shopware 6

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)
[![Shopware 6.6+](https://img.shields.io/badge/Shopware-6.6%2B-blue.svg)](https://shopware.com)
[![Paystack](https://img.shields.io/badge/Payment-Paystack-blueviolet.svg)](https://paystack.com)

![Shopware Paystack Logo](src/Resources/config/shopware.webp)

The **Paystack Payment Plugin** integrates the Paystack payment gateway into Shopware 6, allowing merchants in Africa (Nigeria, Ghana, Kenya, South Africa, and Cote d'Ivoire) to accept payments via credit/debit cards, bank transfers, USSD, and more.

Developed with ❤️ by [Kommandhub Limited](https://kommandhub.com).

---

## 🚀 Features

- **Seamless Integration**: Fully integrates with Shopware 6's checkout flow.
- **Paystack Hosted Checkout**: Redirects customers to a secure Paystack payment page.
- **Support for Multiple Payment Channels**: Supports Cards, Bank Transfers, USSD, QR codes, and more (depending on your Paystack account configuration).
- **Sandbox Mode**: Easy testing with Paystack's sandbox environment.
- **Automatic Order Status Updates**: Automatically transitions order transaction states (e.g., to `Paid`) upon successful verification.
- **Detailed Logging**: Optional debugging mode to log API communications and errors.
- **Custom Metadata**: Stores Paystack reference, transaction ID, and fees directly within the Shopware order transaction.

---

## 📋 Requirements

- **Shopware**: `~6.6.0` or `~6.7.0`
- **PHP**: `^8.2`
- **Paystack Account**: You must have an active [Paystack account](https://dashboard.paystack.com/#/signup).
- **Composer**: To manage dependencies.

---

## 🛠 Installation

### Via Composer (Recommended)

1. Go to your Shopware project root.
2. Require the plugin using Composer:
   ```bash
   composer require kommandhub/paystack-sw
   ```
3. Refresh the plugin list:
   ```bash
   bin/console plugin:refresh
   ```
4. Install and activate the plugin:
   ```bash
   bin/console plugin:install --activate KommandhubPaystackSW
   ```
5. Clear the cache:
   ```bash
   bin/console cache:clear
   ```

### Manual Installation (GitHub Upload)

If you prefer to install the plugin manually via the Shopware Administration:

1. **Download the Plugin**:
   - Go to the [GitHub repository](https://github.com/KommandHub/kommandhub-paystack-sw) (replace with the actual URL).
   - Click on the **Code** button and select **Download ZIP**.
   - Alternatively, download a specific version from the **Releases** page.
2. **Rename the ZIP (if necessary)**:
   - Ensure the ZIP file is named `KommandhubPaystackSW.zip`.
   - The contents of the ZIP should be at the root of the archive (i.e., opening the ZIP should show `src`, `composer.json`, etc.).
3. **Upload via Admin**:
   - Log in to your Shopware Administration.
   - Navigate to **Extensions > My extensions**.
   - Click the **Upload extension** button in the top right corner.
   - Select the `KommandhubPaystackSW.zip` file.
4. **Install & Activate**:
   - Once uploaded, find **Paystack for Shopware** in the list.
   - Click **Install** and then toggle the **Active** switch to `on`.

---

## ⚙️ Configuration

Once installed, navigate to the Shopware Administration:

1. Go to **Extensions > My extensions**.
2. Find **Paystack for Shopware** and click the three dots `...` -> **Configuration**.

### Live Configuration
- **Secret Key**: Enter your Paystack Live Secret Key (starts with `sk_live_`).

### Sandbox Configuration
- **Enable Sandbox**: Toggle this switch to `on` for testing.
- **Secret Key**: Enter your Paystack Test Secret Key (starts with `sk_test_`).

### Debugging
- **Enable error logging**: If enabled, the plugin will log detailed information to the Shopware system logs (`var/log/`). Highly recommended for troubleshooting.

### Payment Method Activation
After configuring the API keys:
1. Go to **Settings > Shop > Payment Methods**.
2. Find **Paystack payment** and ensure it is **Active**.
3. Assign the payment method to your **Sales Channels** under **Settings > Shop > Sales Channels**.

---

## 🧪 Development & Testing

The plugin comes with a `Makefile` to simplify development tasks.

### Running Tests
To run unit and integration tests (requires a running Shopware environment):
```bash
make test
```

### Code Style & Analysis
Run PHP CS Fixer:
```bash
make style
```
Run PHPStan:
```bash
make static-analysis
```

### Coverage
Generate a test coverage report:
```bash
make coverage
```

---

## 📄 License

This project is licensed under the MIT License - see the `LICENSE` file for details.

---

## 🤝 Support

For support, please contact [admin@kommandhub.com](mailto:admin@kommandhub.com) or visit our website [kommandhub.com](https://kommandhub.com).
