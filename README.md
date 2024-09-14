# ReflectionAnyLLM

This project demonstrates a basic chain-of-thought interaction with any LLM (Large Language Model), whether local or hosted remotely, provided it supports the OpenAI-compatible API. You can easily swap out the endpoint and API key to work with various providers such as:

- LMSTUDIO
- Groq
- OpenRouter
- OpenAI API

## Getting Started

To run this project, you'll need a local web server such as:

- **XAMPP** (Windows)
- **MAMP** (Mac)
- **LAMP** stack (Linux)

While MySQL is not required, you will need a web server capable of running PHP with the `curl` extension. Simply running `php -S` won't work out of the box because the project depends on the `curl` extension.

### Steps to Set Up:

1. Download and install the required web server (XAMPP, MAMP, or LAMP).
2. Copy the `index.html` and `chat.php` files to your public web directory:
   - **XAMPP**: `htdocs`
   - **MAMP**: `htdocs`
   - **LAMP**: `/var/www`
3. Ensure the PHP `curl` extension is enabled on your server.

### Accessing the Application:

- **Local Setup**: If you are using a local web server, navigate to `http://localhost/` in your browser.
- **Online Hosting**: If deploying on an online server, navigate to your domain and the folder where the files are uploaded.

### File Structure:

```plaintext
├── index.html   # The main HTML file for the front-end
└── chat.php     # PHP script to handle the backend logic
```

## Disclaimer

This project is purely for fun, and I usually don’t share this type of work, but I was asked to do so on Reddit. If you want to fork it and improve upon it, I’ll make sure to accept your commits.

A few things to note:
- The chain-of-thought implementation is only 2 steps because my M1 Mac is slow, and I can’t afford the wait. You can expand it as much as you want by calling the function repeatedly.
- **Important**: The front-end and back-end code is **not secure** and should **not be used for production** environments.
- I uploaded this in a hurry. If you'd like me to continue working on it, let me know—but I don’t believe it's worth much at this point.

Feel free to explore, modify, and share your improvements!

