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

app.post("/send-login-otp", async (req, res) => {
    try {
        const { email, firstName, otp } = req.body;
        if (typeof email !== "string" || typeof firstName !== "string" || typeof otp !== "string" || !/^\d{6}$/.test(otp)) {
            return res.status(400).json({ success: false, message: "Missing or invalid login code information." });
        }

        const safeName = escapeHtml(firstName);
        const info = await deliver({
            from: `"Brewski" <${fromAddress}>`,
            to: email,
            subject: "Your Brewski login code",
            text: `Hello ${firstName},\n\nYour Brewski login code is ${otp}. It expires in 10 minutes.\n\nIf you did not try to log in, you can ignore this email.`,
            html: `<div style="font-family:Arial,sans-serif;max-width:480px;margin:auto;color:#1e110a"><h2>Your Brewski login code</h2><p>Hello ${safeName}, use this code to finish logging in:</p><p style="font-size:32px;font-weight:bold;letter-spacing:8px;background:#f1e2ca;padding:14px 20px;display:inline-block">${otp}</p><p style="font-size:12px;color:#71492a">This code expires in 10 minutes.</p></div>`
        });

        console.log("Login OTP email sent:", info.messageId);
        return res.json({ success: true });
    } catch (error) {
        console.error("Login OTP email error:", error instanceof Error ? error.message : error);
        return res.status(500).json({ success: false, message: "Unable to send login code." });
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