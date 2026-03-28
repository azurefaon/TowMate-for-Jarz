document.getElementById("sendBtn")?.addEventListener("click", () => {
    const input = document.getElementById("chatInput");
    const chatId = document.getElementById("sendBtn").dataset.chatId;

    if (!input.value.trim()) return;

    fetch(`/customer/chat/${chatId}/send`, {
        method: "POST",
        headers: {
            "Content-Type": "application/json",
            "X-CSRF-TOKEN": document.querySelector('meta[name="csrf-token"]')
                .content,
        },
        body: JSON.stringify({
            message: input.value,
        }),
    })
        .then((res) => res.json())
        .then(() => location.reload());
});
