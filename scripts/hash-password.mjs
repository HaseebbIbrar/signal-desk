#!/usr/bin/env node
// Create one AUTH_USERS entry:  email|Display Name|<base64 bcrypt hash>
// Usage: npm run hash-password            (asks for email, name and password; password is hidden)
// Join several entries with ";" in AUTH_USERS.

import bcrypt from "bcryptjs";
import readline from "node:readline";

function ask(question, { hidden = false } = {}) {
  return new Promise((resolve) => {
    const rl = readline.createInterface({ input: process.stdin, output: process.stdout, terminal: true });
    if (hidden) {
      rl._writeToOutput = (s) => {
        if (s.includes(question)) rl.output.write(s);
        else if (s === "\r\n" || s === "\n") rl.output.write(s);
        else rl.output.write("*");
      };
    }
    rl.question(question, (answer) => {
      rl.close();
      if (hidden) process.stdout.write("\n");
      resolve(answer);
    });
  });
}

const email = (await ask("Email: ")).trim().toLowerCase();
if (!/^[^@\s|;]+@[^@\s|;]+$/.test(email)) {
  console.error("That does not look like a valid email.");
  process.exit(1);
}
const name = (await ask("Display name (e.g. Jonathan Perkins): ")).trim() || email;
if (/[|;]/.test(name)) {
  console.error('The name must not contain "|" or ";".');
  process.exit(1);
}
const password = await ask("Password (min 12 characters): ", { hidden: true });
if (password.length < 12) {
  console.error("Password must be at least 12 characters.");
  process.exit(1);
}
const confirm = await ask("Repeat password: ", { hidden: true });
if (confirm !== password) {
  console.error("Passwords do not match.");
  process.exit(1);
}

const hash = await bcrypt.hash(password, 12);
const entry = `${email}|${name}|${Buffer.from(hash, "utf8").toString("base64")}`;
console.log("\nAUTH_USERS entry (join several users with ';'):\n");
console.log(entry);
console.log("\nExample:\nAUTH_USERS=" + entry);
