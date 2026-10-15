require("dotenv").config();

const express = require("express");

const app = express();

app.use(express.json({ limit: "100kb" }));

const PORT = Number(process.env.PORT || 3000);
const HOST = process.env.HOST?.trim() || "0.0.0.0";

// Mailjet is called over HTTPS (port 443). Render's free plan blocks the SMTP
// ports (25, 465, 587), so Gmail SMTP cannot be used there.
const MAILJET_API = "https://api.mailjet.com";
const mailjetApiKey = process.env.MAILJET_API_KEY?.trim();
const mailjetSecretKey = process.env.MAILJET_SECRET_KEY?.trim();
const mailjetSender = process.env.MAILJET_SENDER?.trim();
const mailjetSenderName = process.env.MAILJET_SENDER_NAME?.trim() || "Brewski";

const consoleMode =
    process.env.MAIL_MODE?.trim().toLowerCase() === "console";

if (!consoleMode && (!mailjetApiKey || !mailjetSecretKey || !mailjetSender)) {
    console.error("Email configuration is missing.");
    console.error(
        "Set MAILJET_API_KEY, MAILJET_SECRET_KEY and MAILJET_SENDER in your environment."
    );
    process.exit(1);
}

// Kept for the "from" field the route handlers pass to deliver(); the actual
// sender used is always the validated MAILJET_SENDER.
const fromAddress = mailjetSender || "dev@brewski.local";

const mailjetAuthHeader =
    "Basic " +
    Buffer.from(`${mailjetApiKey}:${mailjetSecretKey}`).toString("base64");

async function deliver(mail) {
    if (consoleMode) {
        console.log("");
        console.log("========== DEV MODE: EMAIL NOT SENT ==========");
        console.log("To:", mail.to);
        console.log("Subject:", mail.subject);
        console.log("");
        console.log(mail.text);
        console.log("==============================================");
        console.log("");

        return { messageId: "console-mode" };
    }

    const response = await fetch(`${MAILJET_API}/v3.1/send`, {
        method: "POST",
        headers: {
            "Content-Type": "application/json",
            Authorization: mailjetAuthHeader
        },
        body: JSON.stringify({
            Messages: [
                {
                    From: { Email: mailjetSender, Name: mailjetSenderName },
                    To: [{ Email: mail.to }],
                    Subject: mail.subject,
                    TextPart: mail.text,
                    HTMLPart: mail.html
                }
            ]
        }),
        signal: AbortSignal.timeout(20000)
    });

    const raw = await response.text();
    let data = null;

    try {
        data = JSON.parse(raw);
    } catch {
        data = null;
    }

    const message = data?.Messages?.[0];

    if (!response.ok || !message || message.Status !== "success") {
        const detail =
            message?.Errors?.map((e) => e.ErrorMessage).join("; ") ||
            data?.ErrorMessage ||
            raw.slice(0, 300);

        throw new Error(`Mailjet returned HTTP ${response.status}: ${detail}`);
    }

    return {
        messageId: String(message.To?.[0]?.MessageID ?? message.To?.[0]?.MessageUUID ?? "mailjet")
    };
}

app.get("/health", (_req, res) => {
    res.json({
        success: true,
        service: "Brewski Email Service",
        mailMode: consoleMode ? "console" : "mailjet"
    });
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

        const safeDeliveryNotes =
            typeof deliveryNotes === "string"
                ? deliveryNotes.trim()
                : "";

        const itemText = items.map((item) =>
            `${item.name} (${item.size}) x ${item.quantity} - PHP ${formatAmount(item.lineTotal)}`
        ).join("\n");

        const deliveryNotesText = safeDeliveryNotes
            ? `\nDelivery notes: ${safeDeliveryNotes}\n`
            : "";

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
            text: `Hello ${customerName},

Thank you for your order. Here is your receipt.

Receipt: ${receiptNumber}
Order date: ${orderDate}
Delivery address: ${addressText}${deliveryNotesText}

${itemText}

Subtotal: PHP ${formatAmount(subtotal)}
Delivery fee: PHP ${formatAmount(deliveryFee)}
Total: PHP ${formatAmount(total)}
`,
            html: `
                <div style="font-family:Arial,sans-serif;max-width:640px;margin:auto;color:#1a0f0a">
                    <h2>Brewski order receipt</h2>
                    <p>Hello ${safeCustomerName}, thank you for your order.</p>
                    <p>
                        <strong>Receipt:</strong> ${safeReceiptNumber}<br>
                        <strong>Order date:</strong> ${safeOrderDate}<br>
                        <strong>Delivery address:</strong> ${safeAddress}
                    </p>
                    ${deliveryNotesHtml}
                    <table style="width:100%;border-collapse:collapse">
                        <thead>
                            <tr>
                                <th style="padding:10px;text-align:left;border-bottom:2px solid #5c4033">Item</th>
                                <th style="padding:10px;border-bottom:2px solid #5c4033">Qty</th>
                                <th style="padding:10px;text-align:right;border-bottom:2px solid #5c4033">Amount</th>
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

        console.log("Order receipt email sent:", info.messageId);

        return res.json({ success: true });
    } catch (error) {
        console.error(
            "Order receipt email error:",
            error instanceof Error ? error.message : error
        );

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

        const safeName = escapeHtml(firstName);

        const info = await deliver({
            from: `"Brewski" <${fromAddress}>`,
            to: email,
            subject: "Your Brewski order verification code",
            text: `Hello ${firstName},

Your Brewski order verification code is ${otp}. It expires in 10 minutes. You have up to 5 attempts to enter it.

If you did not try to place an order, you can ignore this email.`,
            html: `
                <div style="font-family:Arial,sans-serif;max-width:480px;margin:auto;color:#1e110a">
                    <h2>Verify your Brewski order</h2>
                    <p>Hello ${safeName}, enter this code in the order confirmation window to continue:</p>
                    <p style="font-size:32px;font-weight:bold;letter-spacing:8px;background:#f1e2ca;padding:14px 20px;display:inline-block">
                        ${otp}
                    </p>
                    <p>This code expires in 10 minutes. You have up to 5 attempts.</p>
                    <p style="font-size:12px;color:#71492a">
                        If you did not try to place an order, you can ignore this email.
                    </p>
                </div>
            `
        });

        console.log("Order verification email sent:", info.messageId);

        return res.json({ success: true });
    } catch (error) {
        console.error(
            "Order verification email error:",
            error instanceof Error ? error.message : error
        );

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

        const safeName = escapeHtml(firstName);
        const safeUrl = escapeHtml(parsedUrl.toString());

        const info = await deliver({
            from: `"Brewski" <${fromAddress}>`,
            to: email,
            subject: "Activate your Brewski account",
            text: `Hello ${firstName},

Activate your account using this link (valid for 24 hours):
${parsedUrl.toString()}

If you did not create an account, you can ignore this email.`,
            html: `
                <div style="font-family:Arial,sans-serif;max-width:480px;margin:auto;color:#1e110a">
                    <h2>Welcome to Brewski, ${safeName}!</h2>
                    <p>Click below to activate your account. This link expires in 24 hours.</p>
                    <p>
                        <a href="${safeUrl}" style="display:inline-block;padding:12px 24px;background:#1e110a;color:#f1e2ca;text-decoration:none;border-radius:6px">
                            Activate my account
                        </a>
                    </p>
                    <p style="font-size:12px;color:#71492a">
                        If you did not create an account, you can ignore this email.
                    </p>
                </div>
            `
        });

        console.log("Activation email sent:", info.messageId);

        return res.json({ success: true });
    } catch (error) {
        console.error(
            "Activation email error:",
            error instanceof Error ? error.message : error
        );

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

async function checkMailjet() {
    try {
        const response = await fetch(`${MAILJET_API}/v3/REST/sender?Limit=100`, {
            headers: { Authorization: mailjetAuthHeader },
            signal: AbortSignal.timeout(15000)
        });

        if (response.status === 401) {
            console.error("Mailjet rejected the API key or secret key. Check MAILJET_API_KEY and MAILJET_SECRET_KEY.");
            return;
        }

        if (!response.ok) {
            console.error(`Mailjet check failed with HTTP ${response.status}.`);
            return;
        }

        const data = await response.json();
        const sender = (data.Data || []).find(
            (entry) => String(entry.Email).toLowerCase() === mailjetSender.toLowerCase()
        );

        if (!sender) {
            console.error(`Sender ${mailjetSender} is not registered in Mailjet. Add and validate it under Sender domains & addresses.`);
        } else if (sender.Status !== "Active") {
            console.error(`Sender ${mailjetSender} is registered but not validated yet (status: ${sender.Status}). Click the link in Mailjet's validation email.`);
        } else {
            console.log(`Mailjet connection successful. Sending as ${mailjetSender}.`);
        }
    } catch (error) {
        console.error("Mailjet check failed:", error.message);
    }
}

async function start() {
    app.listen(PORT, HOST, () => {
        console.log(`Brewski email service listening on ${HOST}:${PORT}`);

        if (consoleMode) {
            console.log("DEV MODE: Emails will be printed to the terminal.");
            return;
        }

        checkMailjet();
    });
}

start();