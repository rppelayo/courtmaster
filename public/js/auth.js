async function login(email, password) {
    const formData = new FormData();
    formData.append("action", "login");
    formData.append("email", email);
    formData.append("password", password);
  
    const response = await fetch("../api/auth.php", {
      method: "POST",
      body: formData
    });
  
    const result = await response.json();
    if (result.success) {
      window.location.href = "dashboard.php";
    } else {
      alert(result.error);
    }
  }
  