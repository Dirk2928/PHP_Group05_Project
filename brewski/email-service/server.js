require("dotenv").config();

const express = require("express");
const nodemailer = require("nodemailer");

const app = express();

app.use(express.json());

const PORT = Number(process.env.PORT || 3000);
const HOST = "127.0.0.1";
const emailUser = process.env.EMAIL_USER?.trim();
const emailPassword = process.env.EMAIL_PASSWORD?.trim();

const consoleMode = process.env.MAIL_MODE?.trim().toLowerCase() === "console";

if (!consoleMode && (!emailUser || !emailPassword)) {
    console.error("Email configuration is missing.");
    console.error("Set EMAIL_USER and EMAIL_PASSWORD in email-service/.env.");
    console.error("Or set MAIL_MODE=console to print emails in this terminal while testing.");
    process.exit(1);
}

const fromAddress = emailUser || "dev@brewski.local";

const transporter = consoleMode
    ? null
    : nodemailer.createTransport({
        service: "gmail",

        auth: {
            user: emailUser,
            pass: emailPassword
        }
    });

async function deliver(mail) {
    if (consoleMode) {
        console.log("");
        console.log("================ DEV MODE: email NOT sent ================");
        console.log("To:     ", mail.to);
        console.log("Subject:", mail.subject);
        console.log("");
        console.log(mail.text);
        console.log("==========================================================");
        console.log("");
        return { messageId: "console-mode" };
    }

    return transporter.sendMail(mail);
}

app.get("/health", (_req, res) => {
    res.json({ success: true });
});

const escapeHtml = (value) => value.replace(/[&<>"']/g, (character) => ({
    "&": "&amp;",
    "<": "&lt;",
    ">": "&gt;",
    '"': "&quot;",
    "'": "&#39;"
})[character]);

app.post("/send-order-receipt", async (req, res) => {
    try {
        const {
            email,
            customerName,
            receiptNumber,
            orderDate,
            address,
            deliveryNotes,
            items,
            subtotal,
            deliveryFee,
            total
        } = req.body;
        const hasText = (value, maxLength) =>
            typeof value === "string" && value.trim().length > 0 && value.length <= maxLength;
        const hasAmount = (value) => {
            if (typeof value === "number") {
                return Number.isFinite(value) && value >= 0;
            }
            return typeof value === "string"
                && /^\d+(?:\.\d{1,2})?$/.test(value)
                && Number.isFinite(Number(value));
        };

        if (
            !hasText(email, 254)
            || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)
            || !hasText(customerName, 200)
            || !hasText(receiptNumber, 40)
            || !hasText(orderDate, 40)
            || !address
            || !hasText(address.addressLine, 255)
            || !hasText(address.city, 100)
            || !hasText(address.province, 100)
            || !hasText(address.postalCode, 20)
            || (deliveryNotes !== undefined
                && (typeof deliveryNotes !== "string" || deliveryNotes.length > 500))
            || !Array.isArray(items)
            || items.length === 0
            || items.length > 100
            || !items.every((item) =>
                item
                && hasText(item.name, 255)
                && hasText(item.size, 100)
                && Number.isInteger(item.quantity)
                && item.quantity > 0
                && item.quantity <= 99
                && hasAmount(item.unitPrice)
                && hasAmount(item.lineTotal)
            )
            || !hasAmount(subtotal)
            || !hasAmount(deliveryFee)
            || !hasAmount(total)
        ) {
            return res.status(400).json({ success: false, message: "Missing or invalid receipt information." });
        }

        const addressText = [
            address.addressLine,
            address.city,
            address.province,
            address.postalCode
        ].join(", ");
        const safeDeliveryNotes = typeof deliveryNotes === "string" ? deliveryNotes.trim() : "";
        const formatAmount = (amount) => Number(amount).toFixed(2);
        const itemText = items.map((item) =>
            `${item.name} (${item.size}) x ${item.quantity} - PHP ${formatAmount(item.lineTotal)}`
        ).join("\n");
        const deliveryNotesText = safeDeliveryNotes
            ? `\nDelivery notes: ${safeDeliveryNotes}\n`
            : "";
        const itemRows = items.map((item) => `
            <tr>
                <td style="padding:10px;border-bottom:1px solid #e8dac4">${escapeHtml(item.name)} (${escapeHtml(item.size)})</td>
                <td style="padding:10px;border-bottom:1px solid #e8dac4;text-align:center">${item.quantity}</td>
                <td style="padding:10px;border-bottom:1px solid #e8dac4;text-align:right">PHP ${formatAmount(item.lineTotal)}</td>
            </tr>`
        ).join("");
        const safeCustomerName = escapeHtml(customerName);
        const safeReceiptNumber = escapeHtml(receiptNumber);
        const safeOrderDate = escapeHtml(orderDate);
        const safeAddress = escapeHtml(addressText);
        const deliveryNotesHtml = safeDeliveryNotes
            ? `<p><strong>Delivery notes:</strong><br>${escapeHtml(safeDeliveryNotes).replace(/\r?\n/g, "<br>")}</p>`
            : "";
        const info = await deliver({
            from: `"Brewski" <${fromAddress}>`,
            to: email,
            subject: `Your Brewski order receipt - ${receiptNumber}`,
            text: `Hello ${customerName},\n\nThank you for your order. Here is your receipt.\n\nReceipt: ${receiptNumber}\nOrder date: ${orderDate}\nDelivery address: ${addressText}${deliveryNotesText}\n\n${itemText}\n\nSubtotal: PHP ${formatAmount(subtotal)}\nDelivery fee: PHP ${formatAmount(deliveryFee)}\nTotal: PHP ${formatAmount(total)}\n`,
            html: `<div style="font-family:Arial,sans-serif;max-width:640px;margin:auto;color:#1a0f0a"><h2>Brewski order receipt</h2><p>Hello ${safeCustomerName}, thank you for your order.</p><p><strong>Receipt:</strong> ${safeReceiptNumber}<br><strong>Order date:</strong> ${safeOrderDate}<br><strong>Delivery address:</strong> ${safeAddress}</p>${deliveryNotesHtml}<table style="width:100%;border-collapse:collapse"><thead><tr><th style="padding:10px;text-align:left;border-bottom:2px solid #5c4033">Item</th><th style="padding:10px;border-bottom:2px solid #5c4033">Qty</th><th style="padding:10px;text-align:right;border-bottom:2px solid #5c4033">Amount</th></tr></thead><tbody>${itemRows}</tbody></table><p style="text-align:right">Subtotal: PHP ${formatAmount(subtotal)}<br>Delivery fee: PHP ${formatAmount(deliveryFee)}<br><strong>Total: PHP ${formatAmount(total)}</strong></p></div>`
        });

        console.log("Order receipt email sent:", info.messageId);
        return res.json({ success: true });
    } catch (error) {
        console.error("Order receipt email error:", error instanceof Error ? error.message : error);
        return res.status(500).json({ success: false, message: "Unable to send order receipt." });
    }
});

app.post("/send-order-otp", async (req, res) => {
    try {
        const { email, firstName, otp } = req.body;
        if (
            typeof email !== "string"
            || email.length > 254
            || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)
            || typeof firstName !== "string"
            || firstName.trim().length === 0
            || firstName.length > 100
            || typeof otp !== "string"
            || !/^\d{6}$/.test(otp)
        ) {
            return res.status(400).json({ success: false, message: "Missing or invalid order verification information." });
        }

        const safeName = escapeHtml(firstName);
        const info = await deliver({
            from: `"Brewski" <${fromAddress}>`,
            to: email,
            subject: "Your Brewski order verification code",
            text: `Hello ${firstName},\n\nYour Brewski order verification code is ${otp}. It expires in 10 minutes. You have up to 5 attempts to enter it.\n\nIf you did not try to place an order, you can ignore this email.`,
            html: `<div style="font-family:Arial,sans-serif;max-width:480px;margin:auto;color:#1e110a"><h2>Verify your Brewski order</h2><p>Hello ${safeName}, enter this code in the order confirmation window to continue:</p><p style="font-size:32px;font-weight:bold;letter-spacing:8px;background:#f1e2ca;padding:14px 20px;display:inline-block">${otp}</p><p>This code expires in 10 minutes. You have up to 5 attempts.</p><p style="font-size:12px;color:#71492a">If you did not try to place an order, you can ignore this email.</p></div>`
        });

        console.log("Order verification email sent:", info.messageId);
        return res.json({ success: true });
    } catch (error) {
        console.error("Order verification email error:", error instanceof Error ? error.message : error);
        return res.status(500).json({ success: false, message: "Unable to send order verification code." });
    }
});

app.post("/send-activation", async (req, res) => {
    try {
        const { email, firstName, activationUrl } = req.body;
        if (typeof email !== "string" || typeof firstName !== "string" || typeof activationUrl !== "string") {
            return res.status(400).json({ success: false, message: "Missing required information." });
        }

        const parsedUrl = new URL(activationUrl);
        if (!['http:', 'https:'].includes(parsedUrl.protocol)) {
            return res.status(400).json({ success: false, message: "Invalid activation link." });
        }

        const safeName = escapeHtml(firstName);
        const safeUrl = escapeHtml(parsedUrl.toString());
        const info = await deliver({
            from: `"Brewski" <${fromAddress}>`,
            to: email,
            subject: "Activate your Brewski account",
            text: `Hello ${firstName},\n\nActivate your account using this link (valid for 24 hours):\n${parsedUrl.toString()}\n\nIf you did not create an account, you can ignore this email.`,
            html: `<div style="font-family:Arial,sans-serif;max-width:480px;margin:auto;color:#1e110a"><h2>Welcome to Brewski, ${safeName}!</h2><p>Click below to activate your account. This link expires in 24 hours.</p><p><a href="${safeUrl}" style="display:inline-block;padding:12px 24px;background:#1e110a;color:#f1e2ca;text-decoration:none;border-radius:6px">Activate my account</a></p><p style="font-size:12px;color:#71492a">If you did not create an account, you can ignore this email.</p></div>`
        });

        console.log("Activation email sent:", info.messageId);
        return res.json({ success: true });
    } catch (error) {
        console.error("Activation email error:", error instanceof Error ? error.message : error);
        return res.status(500).json({ success: false, message: "Unable to send activation email." });
    }
});

async function start() {
    try {
        if (consoleMode) {
            console.log("DEV MODE: emails will be printed here instead of being sent through Gmail.");
        } else {
            await transporter.verify();
        }

        app.listen(PORT, HOST, () => {
            console.log(`Brewski email service ready at http://${HOST}:${PORT}`);
        });
    } catch (error) {
        console.error("Could not connect to Gmail SMTP:");
        console.error(error instanceof Error ? error.message : error);
        process.exit(1);
    }
}

start();