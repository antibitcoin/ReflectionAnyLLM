# ReflectionAnyLLM

[![Watch the Video](ReflectionAnyLLM.mp4)](ReflectionAnyLLM.mp4)  
*Note: The video quality might not be optimal—apologies for that.*

**Online Demo**: [freemindgpt.com/reflectionanyllm](https://freemindgpt.com/reflectionanyllm/)  
*Important*: This demo is running on OpenRouter's free tier, utilizing `google/gemma-2-9b-it:free`, which is a relatively small model. While it showcases basic functionality, for more advanced use cases, models like GPT-4o, LLaMA 3.1 70b, or 405b would offer far better performance. The demo may also occasionally stop working if usage limits are exceeded.

---

## Project Overview

**ReflectionAnyLLM** is a lightweight proof-of-concept that enables basic chain-of-thought (CoT) reasoning with any Large Language Model (LLM) that supports the OpenAI-compatible API. The flexibility of this project allows it to interface with local or remote LLMs, giving you the ability to switch between providers with minimal setup.

The project can be integrated with multiple LLM providers, including but not limited to:

- **LMSTUDIO**
- **Groq**
- **OpenRouter**
- **OpenAI API**

By replacing the API key and endpoint, you can easily adapt this project to work with other LLMs that support OpenAI's API format.

---

## Features

- **Chain-of-Thought Reasoning**: ReflectionAnyLLM implements a 2-step reasoning process, allowing you to explore how CoT works with basic LLM interactions.
- **API Flexibility**: Easily switch between different LLM providers by modifying the API endpoint and key.
- **Simple Setup**: The project is lightweight and doesn't require a complex database or backend setup.
  
---

## Getting Started

To get started with ReflectionAnyLLM, you’ll need a local web server. The following servers are recommended based on your operating system:

- **Windows**: [XAMPP](https://www.apachefriends.org/index.html)
- **Mac**: [MAMP](https://www.mamp.info/en/)
- **Linux**: Use the **LAMP** stack.

**Note**: MySQL is not required, but your server must have PHP installed with the `curl` extension enabled. Running `php -S` (PHP's built-in server) will not work out of the box, as this project relies on the `curl` extension to handle API requests.

### Prerequisites

Before running the project, ensure you have the following:

1. **Web Server**: Download and install one of the following:
   - **XAMPP** for Windows
   - **MAMP** for Mac
   - **LAMP** for Linux
2. **PHP with `curl`**: Ensure the `curl` extension is enabled in your PHP configuration. This is critical for handling API requests.

---

## Installation Instructions

1. Clone this repository or download the project files.
2. Move the `index.html` and `chat.php` files to your web server’s public directory:
   - **XAMPP**: `htdocs/`
   - **MAMP**: `htdocs/`
   - **LAMP**: `/var/www/`
3. Make sure the `curl` extension is active on your PHP server:
   - In your `php.ini` file, ensure that `extension=curl` is not commented out.
4. Once set up, navigate to the project directory through your browser:

   - **Local Setup**: Open `http://localhost/` in your web browser.
   - **Online Hosting**: Upload the files to your remote server and access it via your domain name.

### Directory Structure

```plaintext
ReflectionAnyLLM/
├── index.html   # Front-end HTML file
└── chat.php     # Backend PHP script for handling API requests
```

---

## Usage

The front-end interacts with the back-end PHP script, which makes API requests to the LLM. You can modify the API settings by editing the `chat.php` file, where you can:

- **Change the API endpoint**: Replace the OpenAI-compatible API URL.
- **Update the API key**: Input your API key for the LLM you are using (e.g., OpenRouter, Groq, etc.).

This flexibility allows the project to be used with any compatible LLM service, enabling quick testing and iteration.

---

## Notes & Considerations

This project was developed as a quick demo and should be considered a **prototype**. Here are some key things to note:

- **Chain of Thought (CoT) Reasoning**: The current implementation only supports a 2-step CoT interaction, mainly because of the performance limitations of my M1 Mac. However, you can extend this by modifying the logic to call the function iteratively.
- **Security Warning**: The code, especially the back-end `chat.php`, is **not secured** and is not suitable for production environments. It was created quickly to demonstrate basic functionality and lacks robust security practices.
- **Improvement Potential**: Although this project is functional, it is by no means polished or optimized. If you're interested in improving it, feel free to fork the repository and submit a pull request. I'm open to reviewing and merging community contributions.

---

## Future Improvements

While this project serves as a simple demonstration of basic LLM interactions, it can be expanded in several ways:

1. **Enhanced Chain-of-Thought**: Extend the CoT process to allow for more complex reasoning and interaction.
2. **UI/UX Improvements**: Improve the front-end design and add features like loading indicators and error handling.
3. **Security Enhancements**: Implement more secure coding practices, especially on the back-end, to prevent potential vulnerabilities.

If there’s interest from the community, I may continue developing and improving this project. Feedback and suggestions are welcome!

---

## Contributing

I created this project based on a request from Reddit, and I’m happy to share it with the community. If you find ways to improve it or want to contribute new features, please fork the repository and submit a pull request.

---

## License

This project is licensed under the MIT License, meaning you're free to use, modify, and distribute it, as long as attribution is provided.

---

Feel free to explore, modify, and share your improvements! If you have any questions or encounter issues, don’t hesitate to reach out.
