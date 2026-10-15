
require("dotenv").config();

const express = require("express");
const { Resend } = require("resend");

const app = express();

app.use(express.json({ limit: "100kb" }));

const PORT = Number(process.env.PORT || 3000);
const HOST = process.env.HOST?.trim() || "0.0.0.0";
const resendApiKey = process.env.RESEND_API_KEY?.trim();
const emailFrom = process.env.EMAIL_FROM?.trim();
const consoleMode = process.env.MAIL_MODE?.trim().toLowerCase() === "console";

if (!consoleMode && (!resendApiKey || !emailFrom)) {
    console.error("Email configuration is missing.");
    console.error("Set RESEND_API_KEY and EMAIL_FROM.");
    process.exit(1);
}

const resend = consoleMode ? null : new Resend(resendApiKey);

app.use((req, res, next) => {
    next();
});

const escapeHtml = (value) =>
    String(value).replace(/[&<>"']/g, (character) => ({
        "&": "&amp;",
        "<": "&lt;",
        ">": "&gt;",
        '"': "&quot;",
        "'": "&#39;"
    })[character]);

const hasText = (value, maxLength) =>
    typeof value === "string" &&
    value.trim().length > 0 &&
    value.length <= maxLength;

const hasValidEmail = (email) =>
    hasText(email, 254) &&
    /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);

const hasAmount = (value) => {
    if (typeof value === "number") {
        return Number.isFinite(value) && value >= 0;
    }

    return (
        typeof value === "string" &&
        /^\d+(?:\.\d{1,2})?$/.test(value) &&
        Number.isFinite(Number(value))
    );
};

const formatAmount = (amount) => Number(amount).toFixed(2);

async function deliver(mail) {
    if (consoleMode) {
        console.log("DEV MODE: Email not sent.");
        console.log("To:", mail.to);
        console.log("Subject:", mail.subject);
        console.log(mail.text);
        return { messageId: "console-mode" };
    }

    const { data, error } = await resend.emails.send({
        from: mail.from,
        to: mail.to,
        subject: mail.subject,
        text: mail.text,
        html: mail.html
    });

    if (error) {
        console.error("Resend API error:", error);
        throw new Error(error.message || "Resend email delivery failed");
    }

    if (!data?.id) {
        throw new Error("Resend did not return an email ID.");
    }

    console.log("Resend accepted email:", data.id);

    return { messageId: data.id };
}

app.get("/health", (_req, res) => {
    res.json({
        success: true,
        service: "Brewski Email Service",
        mailMode: consoleMode ? "console" : "resend"
    });
});

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

        if (
            !hasValidEmail(email) ||
            !hasText(customerName, 200) ||
            !hasText(receiptNumber, 40) ||
            !hasText(orderDate, 40) ||
            !address ||
            typeof address !== "object" ||
            !hasText(address.addressLine, 255) ||
            !hasText(address.city, 100) ||
            !hasText(address.province, 100) ||
            !hasText(address.postalCode, 20) ||
            (
                deliveryNotes !== undefined &&
                (
                    typeof deliveryNotes !== "string" ||
                    deliveryNotes.length > 500
                )
            ) ||
            !Array.isArray(items) ||
            items.length === 0 ||
            items.length > 100 ||
            !items.every((item) =>
                item &&
                hasText(item.name, 255) &&
                hasText(item.size, 100) &&
                Number.isInteger(item.quantity) &&
                item.quantity > 0 &&
                item.quantity <= 99 &&
                hasAmount(item.unitPrice) &&
                hasAmount(item.lineTotal)
            ) ||
            !hasAmount(subtotal) ||
            !hasAmount(deliveryFee) ||
            !hasAmount(total)
        ) {
            return res.status(400).json({
                success: false,
                message: "Missing or invalid receipt information."
            });
        }

        const addressText = [
            address.addressLine,
            address.city,
            address.province,
            address.postalCode
        ].join(", ");

        const notes = typeof deliveryNotes === "string"
            ? deliveryNotes.trim()
            : "";

        const itemText = items.map((item) =>
            `${item.name} (${item.size}) x ${item.quantity} - PHP ${formatAmount(item.lineTotal)}`
        ).join("\n");

        const itemRows = items.map((item) => `
            <tr>
                <td style="padding:10px;border-bottom:1px solid #e8dac4">
                    ${escapeHtml(item.name)} (${escapeHtml(item.size)})
                </td>
                <td style="padding:10px;border-bottom:1px solid #e8dac4;text-align:center">
                    ${item.quantity}
                </td>
                <td style="padding:10px;border-bottom:1px solid #e8dac4;text-align:right">
                    PHP ${formatAmount(item.lineTotal)}
                </td>
            </tr>
        `).join("");

        const result = await deliver({
            from: emailFrom,
            to: email,
            subject: `Your Brewski order receipt - ${receiptNumber}`,
            text: `Hello ${customerName},

Thank you for your order.

Receipt: ${receiptNumber}
Order date: ${orderDate}
Delivery address: ${addressText}
${notes ? `Delivery notes: ${notes}\n` : ""}

${itemText}

Subtotal: PHP ${formatAmount(subtotal)}
Delivery fee: PHP ${formatAmount(deliveryFee)}
Total: PHP ${formatAmount(total)}`,
            html: `
                <div style="font-family:Arial,sans-serif;max-width:640px;margin:auto;color:#1a0f0a">
                    <h2>Brewski order receipt</h2>
                    <p>Hello ${escapeHtml(customerName)}, thank you for your order.</p>
                    <p>
                        <strong>Receipt:</strong> ${escapeHtml(receiptNumber)}<br>
                        <strong>Order date:</strong> ${escapeHtml(orderDate)}<br>
                        <strong>Delivery address:</strong> ${escapeHtml(addressText)}
                    </p>
                    ${notes ? `<p><strong>Delivery notes:</strong><br>${escapeHtml(notes).replace(/\r?\n/g, "<br>")}</p>` : ""}
                    <table style="width:100%;border-collapse:collapse">
                        <thead>
                            <tr>
                                <th style="padding:10px;text-align:left">Item</th>
                                <th style="padding:10px">Qty</th>
                                <th style="padding:10px;text-align:right">Amount</th>
                            </tr>
                        </thead>
                        <tbody>${itemRows}</tbody>
                    </table>
                    <p style="text-align:right">
                        Subtotal: PHP ${formatAmount(subtotal)}<br>
                        Delivery fee: PHP ${formatAmount(deliveryFee)}<br>
                        <strong>Total: PHP ${formatAmount(total)}</strong>
                    </p>
                </div>
            `
        });

        console.log("Order receipt email sent:", result.messageId);

        return res.json({
            success: true,
            messageId: result.messageId
        });
    } catch (error) {
        console.error("Order receipt email error:", error.message);

        return res.status(500).json({
            success: false,
            message: "Unable to send order receipt."
        });
    }
});

app.post("/send-order-otp", async (req, res) => {
    try {
        const { email, firstName, otp } = req.body;

        if (
            !hasValidEmail(email) ||
            !hasText(firstName, 100) ||
            typeof otp !== "string" ||
            !/^\d{6}$/.test(otp)
        ) {
            return res.status(400).json({
                success: false,
                message: "Missing or invalid order verification information."
            });
        }

        const result = await deliver({
            from: emailFrom,
            to: email,
            subject: "Your Brewski order verification code",
            text: `Hello ${firstName},

Your Brewski order verification code is ${otp}. It expires in 10 minutes. You have up to 5 attempts.

If you did not try to place an order, you can ignore this email.`,
            html: `
                <div style="font-family:Arial,sans-serif;max-width:480px;margin:auto;color:#1e110a">
                    <h2>Verify your Brewski order</h2>
                    <p>Hello ${escapeHtml(firstName)}, enter this code to continue:</p>
                    <p style="font-size:32px;font-weight:bold;letter-spacing:8px;background:#f1e2ca;padding:14px 20px;display:inline-block">
                        ${otp}
                    </p>
                    <p>This code expires in 10 minutes. You have up to 5 attempts.</p>
                    <p>If you did not try to place an order, you can ignore this email.</p>
                </div>
            `
        });

        console.log("Order verification email sent:", result.messageId);

        return res.json({
            success: true,
            messageId: result.messageId
        });
    } catch (error) {
        console.error("Order verification email error:", error.message);

        return res.status(500).json({
            success: false,
            message: "Unable to send order verification code."
        });
    }
});

app.post("/send-activation", async (req, res) => {
    try {
        const { email, firstName, activationUrl } = req.body;

        if (
            !hasValidEmail(email) ||
            !hasText(firstName, 100) ||
            !hasText(activationUrl, 2048)
        ) {
            return res.status(400).json({
                success: false,
                message: "Missing required information."
            });
        }

        let parsedUrl;

        try {
            parsedUrl = new URL(activationUrl);
        } catch {
            return res.status(400).json({
                success: false,
                message: "Invalid activation link."
            });
        }

        if (
            !["http:", "https:"].includes(parsedUrl.protocol) ||
            parsedUrl.username ||
            parsedUrl.password
        ) {
            return res.status(400).json({
                success: false,
                message: "Invalid activation link."
            });
        }

        const result = await deliver({
            from: emailFrom,
            to: email,
            subject: "Activate your Brewski account",
            text: `Hello ${firstName},

Activate your account using this link. It is valid for 24 hours:
${parsedUrl.toString()}

If you did not create an account, you can ignore this email.`,
            html: `
                <div style="font-family:Arial,sans-serif;max-width:480px;margin:auto;color:#1e110a">
                    <h2>Welcome to Brewski, ${escapeHtml(firstName)}!</h2>
                    <p>Click below to activate your account. This link expires in 24 hours.</p>
                    <p>
                        <a href="${escapeHtml(parsedUrl.toString())}" style="display:inline-block;padding:12px 24px;background:#1e110a;color:#f1e2ca;text-decoration:none;border-radius:6px">
                            Activate my account
                        </a>
                    </p>
                    <p style="font-size:12px;color:#71492a">
                        If you did not create an account, you can ignore this email.
                    </p>
                </div>
            `
        });

        console.log("Activation email sent:", result.messageId);

        return res.json({
            success: true,
            messageId: result.messageId
        });
    } catch (error) {
        console.error("Activation email error:", error.message);

        return res.status(500).json({
            success: false,
            message: "Unable to send activation email."
        });
    }
});

app.use((err, _req, res, _next) => {
    console.error("Request error:", err.message);

    if (err.type === "entity.parse.failed") {
        return res.status(400).json({
            success: false,
            message: "Invalid JSON request."
        });
    }

    return res.status(500).json({
        success: false,
        message: "Internal server error."
    });
});

function start() {
    app.listen(PORT, HOST, () => {
        console.log(`Brewski email service listening on ${HOST}:${PORT}`);
        console.log(`Email mode: ${consoleMode ? "console" : "resend"}`);
    });
}

start();
